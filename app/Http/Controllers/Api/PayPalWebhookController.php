<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\CreatorPayout;
use App\Services\Payments\CreatorPayoutFinalizer;
use App\Models\PaypalWebhookEvent;
use App\Services\Payments\CaptureFinalizer;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\RefundFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PayPalWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PayPalClient $paypal,
        CaptureFinalizer $captureFinalizer,
        RefundFinalizer $refundFinalizer,
        CreatorPayoutFinalizer $payoutFinalizer
    ): JsonResponse {
        $event = $request->all();
        $eventId = data_get($event, 'id');
        $eventType = data_get($event, 'event_type');

        if (! is_string($eventId) || ! is_string($eventType)) {
            return response()->json(['message' => 'Invalid PayPal webhook payload.'], 400);
        }

        try {
            $verified = $paypal->verifyWebhook([
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            ], $event);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'PayPal webhook verification is temporarily unavailable.'], 503);
        }

        if (! $verified) {
            return response()->json(['message' => 'Invalid PayPal webhook signature.'], 400);
        }

        $stored = DB::transaction(function () use ($eventId, $eventType, $event): array {
            $row = PaypalWebhookEvent::where('provider_event_id', $eventId)->lockForUpdate()->first();

            if ($row && in_array($row->processing_status, ['processed', 'processing'], true)) {
                return ['row' => $row, 'should_process' => false];
            }

            if (! $row) {
                $row = PaypalWebhookEvent::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'resource_id' => data_get($event, 'resource.id'),
                    'payload' => $event,
                    'processing_status' => 'processing',
                    'received_at' => now(),
                ]);
            } else {
                $row->update([
                    'processing_status' => 'processing',
                    'failure_reason' => null,
                ]);
            }

            return ['row' => $row, 'should_process' => true];
        });

        if (! $stored['should_process']) {
            return response()->json(['status' => $stored['row']->processing_status], 200);
        }

        try {
            if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                $resource = data_get($event, 'resource', []);
                $paypalOrderId = data_get($resource, 'supplementary_data.related_ids.order_id');
                $captureId = data_get($resource, 'id');
                $amount = data_get($resource, 'amount.value');
                $currency = data_get($resource, 'amount.currency_code');

                if (! is_string($paypalOrderId) || ! is_string($captureId) || ! is_string($amount) || ! is_string($currency)) {
                    throw new \RuntimeException('PayPal capture webhook is missing required payment identifiers.');
                }

                $captureFinalizer->finalize($paypalOrderId, $captureId, $paypal->parseMinor($amount), $currency);
            } elseif (in_array($eventType, ['PAYMENT.PAYOUTS-ITEM.SUCCEEDED', 'PAYMENT.PAYOUTS-ITEM.FAILED', 'PAYMENT.PAYOUTS-ITEM.BLOCKED', 'PAYMENT.PAYOUTS-ITEM.CANCELED', 'PAYMENT.PAYOUTS-ITEM.RETURNED', 'PAYMENT.PAYOUTS-ITEM.REFUNDED'], true)) {
                $resource = data_get($event, 'resource', []);
                $senderItemId = data_get($resource, 'sender_item_id');
                $batchId = data_get($resource, 'payout_batch_id');
                $itemId = data_get($resource, 'payout_item_id');
                $payoutQuery = CreatorPayout::query();
                if (is_string($senderItemId) && $senderItemId !== '') {
                    $payoutQuery->where('paypal_sender_item_id', $senderItemId);
                } elseif (is_string($batchId) && $batchId !== '') {
                    $payoutQuery->where('paypal_batch_id', $batchId);
                } else {
                    throw new \RuntimeException('PayPal payout webhook is missing its payout identifiers.');
                }
                $payout = $payoutQuery->firstOrFail();
                $amountValue = data_get($resource, 'amount.value');
                $amountCurrency = data_get($resource, 'amount.currency');
                if (! is_string($amountValue) || ! is_string($amountCurrency)
                    || $paypal->parseMinor($amountValue) !== $payout->amount_minor
                    || strtoupper($amountCurrency) !== $payout->currency) {
                    throw new \RuntimeException('PayPal payout webhook amount does not match the stored payout.');
                }
                $resolvedBatchId = is_string($batchId) && $batchId !== '' ? $batchId : (string) $payout->paypal_batch_id;
                $resolvedItemId = is_string($itemId) ? $itemId : null;
                if ($eventType === 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED') {
                    $payoutFinalizer->complete($payout, $resolvedBatchId, $resolvedItemId);
                } elseif (in_array($eventType, ['PAYMENT.PAYOUTS-ITEM.RETURNED', 'PAYMENT.PAYOUTS-ITEM.REFUNDED'], true)) {
                    $reason = (string) (data_get($resource, 'errors.message') ?? data_get($resource, 'transaction_status') ?? 'O PayPal devolveu o pagamento ao saldo da plataforma.');
                    $payoutFinalizer->returnFunds($payout, $resolvedBatchId, $resolvedItemId, $reason);
                } elseif (in_array($eventType, ['PAYMENT.PAYOUTS-ITEM.FAILED', 'PAYMENT.PAYOUTS-ITEM.BLOCKED', 'PAYMENT.PAYOUTS-ITEM.CANCELED'], true)) {
                    $reason = (string) (data_get($resource, 'errors.message') ?? data_get($resource, 'transaction_status') ?? 'O PayPal não concluiu o pagamento.');
                    $payoutFinalizer->fail($payout, $resolvedBatchId, $resolvedItemId, $reason);
                }
            } elseif ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
                $resource = data_get($event, 'resource', []);
                $providerRefundId = data_get($resource, 'id');
                $amount = data_get($resource, 'amount.value');
                $currency = data_get($resource, 'amount.currency_code');
                $captureUrl = collect(data_get($resource, 'links', []))
                    ->firstWhere('rel', 'up')['href'] ?? null;
                $captureId = is_string($captureUrl) ? basename(parse_url($captureUrl, PHP_URL_PATH) ?: '') : null;

                if (! is_string($providerRefundId) || ! is_string($amount) || ! is_string($currency) || ! is_string($captureId) || $captureId === '') {
                    throw new \RuntimeException('PayPal refund webhook is missing its refund, capture or amount details.');
                }

                $payment = PaymentTransaction::where('provider_capture_id', $captureId)->firstOrFail();
                $refund = PaymentRefund::firstOrCreate(
                    ['provider_refund_id' => $providerRefundId],
                    [
                        'payment_transaction_id' => $payment->id,
                        'idempotency_key' => 'paypal-external-refund-'.hash('sha256', $providerRefundId),
                        'gross_minor' => $paypal->parseMinor($amount),
                        'currency' => strtoupper($currency),
                        'status' => 'pending',
                        'source' => 'paypal_external',
                        'note' => data_get($resource, 'note'),
                    ]
                );

                $refundFinalizer->finalize($refund, $providerRefundId, $paypal->parseMinor($amount), $currency);
            }

            $stored['row']->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
            ]);

            return response()->json(['status' => 'processed'], 200);
        } catch (Throwable $exception) {
            $stored['row']->update([
                'processing_status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
            report($exception);

            return response()->json(['message' => 'Webhook processing failed and may be retried.'], 500);
        }
    }
}
