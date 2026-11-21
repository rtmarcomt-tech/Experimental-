<?php

namespace App\Http\Controllers;

use App\Models\CreatorPayout;
use App\Services\Payments\CreatorPayoutFinalizer;
use App\Services\Payments\PayPalClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminCreatorPayoutController extends Controller
{
    public function submit(int $payoutId, PayPalClient $paypal, CreatorPayoutFinalizer $finalizer): RedirectResponse
    {
        $payout = DB::transaction(function () use ($payoutId): CreatorPayout {
            $payout = CreatorPayout::whereKey($payoutId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($payout->status, ['requested', 'submission_unknown'], true) || ($payout->status === 'processing' && ! $payout->paypal_batch_id), 409);
            $payout->update(['status' => 'processing', 'failure_reason' => null]);
            return $payout->fresh();
        });

        $senderBatchId = 'marketplace-payout-'.$payout->id;
        $senderItemId = 'creator-payout-'.$payout->id;

        try {
            $response = $paypal->createPayout(
                $payout->amount_minor,
                $payout->currency,
                $payout->recipient_email,
                $senderBatchId,
                $senderItemId
            );

            $batchId = data_get($response, 'batch_header.payout_batch_id');
            $batchStatus = strtoupper((string) data_get($response, 'batch_header.batch_status'));
            if (! is_string($batchId) || $batchId === '') {
                throw new \RuntimeException('PayPal did not return a payout batch identifier.');
            }

            $payout->update([
                'paypal_batch_id' => $batchId,
                'paypal_sender_item_id' => $senderItemId,
                'paypal_item_id' => data_get($response, 'items.0.payout_item_id'),
                'status' => 'processing',
                'submitted_at' => now(),
            ]);

            if ($batchStatus === 'DENIED') {
                $finalizer->fail($payout->fresh(), $batchId, data_get($response, 'items.0.payout_item_id'), 'PayPal recusou o lote de pagamentos.');
                return back()->withErrors(['payout' => 'O PayPal recusou o lote. O saldo foi reposto.']);
            }

            return back()->with('success', 'Pedido enviado ao PayPal. O pagamento ficará pendente até confirmação do estado final.');
        } catch (Throwable $exception) {
            $payout->update([
                'status' => 'submission_unknown',
                'paypal_sender_item_id' => $senderItemId,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
            report($exception);

            return back()->withErrors(['payout' => 'Não foi possível confirmar a resposta do PayPal. Os fundos continuam reservados. Pode repetir o envio com a mesma referência idempotente ou reconciliar o lote.']);
        }
    }

    public function reconcile(int $payoutId, PayPalClient $paypal, CreatorPayoutFinalizer $finalizer): RedirectResponse
    {
        $payout = CreatorPayout::findOrFail($payoutId);
        abort_unless(in_array($payout->status, ['processing', 'submission_unknown'], true) && $payout->paypal_batch_id, 409);

        try {
            $batch = $paypal->getPayoutBatch($payout->paypal_batch_id);
            $item = collect(data_get($batch, 'items', []))->first(function ($item) use ($payout) {
                return data_get($item, 'payout_item.sender_item_id') === $payout->paypal_sender_item_id
                    || data_get($item, 'payout_item.payout_item_id') === $payout->paypal_item_id;
            });
            $itemData = data_get($item, 'payout_item', []);
            $status = strtoupper((string) data_get($itemData, 'transaction_status'));
            $amountValue = data_get($itemData, 'amount.value');
            $amountCurrency = data_get($itemData, 'amount.currency');
            if (! is_string($amountValue) || ! is_string($amountCurrency)
                || $paypal->parseMinor($amountValue) !== $payout->amount_minor
                || strtoupper($amountCurrency) !== $payout->currency) {
                throw new \RuntimeException('PayPal payout batch amount does not match the stored payout.');
            }

            if ($status === 'SUCCESS') {
                $finalizer->complete($payout, $payout->paypal_batch_id, data_get($itemData, 'payout_item_id'));
                return back()->with('success', 'PayPal confirmou o pagamento ao criador.');
            }

            if (in_array($status, ['RETURNED', 'REFUNDED'], true)) {
                $reason = (string) (data_get($itemData, 'errors.name') ?? data_get($itemData, 'errors.message') ?? 'O PayPal devolveu o pagamento à plataforma.');
                $finalizer->returnFunds($payout, $payout->paypal_batch_id, data_get($itemData, 'payout_item_id'), $reason);
                return back()->withErrors(['payout' => 'O PayPal devolveu o pagamento. O saldo foi reposto na conta do criador.']);
            }

            if (in_array($status, ['FAILED', 'BLOCKED', 'DENIED', 'CANCELED', 'REVERSED'], true)) {
                $reason = (string) (data_get($itemData, 'errors.name') ?? data_get($itemData, 'errors.message') ?? 'O PayPal não concluiu o pagamento.');
                $finalizer->fail($payout, $payout->paypal_batch_id, data_get($itemData, 'payout_item_id'), $reason);
                return back()->withErrors(['payout' => 'O pagamento falhou. O saldo foi reposto na conta do criador.']);
            }

            return back()->with('success', 'O PayPal ainda não apresenta um estado final para este pagamento.');
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['payout' => 'Não foi possível reconciliar o pagamento com o PayPal. Os fundos permanecem reservados.']);
        }
    }
}
