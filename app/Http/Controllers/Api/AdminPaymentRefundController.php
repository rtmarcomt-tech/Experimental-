<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\RefundFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminPaymentRefundController extends Controller
{
    public function store(Request $request, string $reference, PayPalClient $paypal, RefundFinalizer $finalizer): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $order = Order::where('order_reference', $reference)->with('paymentTransaction')->firstOrFail();
        $payment = $order->paymentTransaction;

        if (! $payment || $payment->status !== 'captured' || ! $payment->provider_capture_id) {
            throw ValidationException::withMessages(['order' => ['Only captured payments can be refunded.']]);
        }

        $key = hash('sha256', 'admin-refund|'.$payment->id.'|'.$data['idempotency_key']);
        $existing = PaymentRefund::where('idempotency_key', $key)->first();

        if ($existing) {
            return response()->json($this->refundPayload($existing), 200);
        }

        $refund = DB::transaction(function () use ($payment, $data, $key): PaymentRefund {
            $lockedPayment = $payment->newQuery()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $reserved = (int) PaymentRefund::where('payment_transaction_id', $lockedPayment->id)
                ->whereIn('status', ['requested', 'pending', 'completed'])
                ->sum('gross_minor');
            $remaining = $lockedPayment->gross_minor - $reserved;
            $amount = $data['amount_minor'] ?? $remaining;

            if ($amount < 1 || $amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount_minor' => ['The requested refund exceeds the remaining refundable balance.'],
                ]);
            }

            return PaymentRefund::create([
                'payment_transaction_id' => $lockedPayment->id,
                'idempotency_key' => $key,
                'gross_minor' => $amount,
                'currency' => $lockedPayment->currency,
                'status' => 'requested',
                'source' => 'admin',
                'note' => $data['note'] ?? null,
            ]);
        });

        try {
            $response = $paypal->refundCapture(
                $payment->provider_capture_id,
                $refund->gross_minor === $payment->gross_minor ? null : $refund->gross_minor,
                $payment->currency,
                hash('sha256', 'paypal-refund|'.$refund->id),
                $refund->note
            );

            $providerId = data_get($response, 'id');
            $providerStatus = strtoupper((string) data_get($response, 'status'));
            if (! is_string($providerId) || $providerId === '') {
                throw new \RuntimeException('PayPal did not return a refund identifier.');
            }

            $refund->update([
                'provider_refund_id' => $providerId,
                'status' => $providerStatus === 'COMPLETED' ? 'requested' : ($providerStatus === 'PENDING' ? 'pending' : 'failed'),
            ]);

            if ($providerStatus === 'COMPLETED') {
                $refund = $finalizer->finalize(
                    $refund,
                    $providerId,
                    $paypal->parseMinor((string) data_get($response, 'amount.value', $paypal->formatMinor($refund->gross_minor))),
                    (string) data_get($response, 'amount.currency_code', $refund->currency)
                );
            } elseif ($providerStatus !== 'PENDING') {
                throw new \RuntimeException('PayPal did not complete the refund.');
            }

            return response()->json($this->refundPayload($refund->fresh()), 202);
        } catch (Throwable $exception) {
            if (! $refund->provider_refund_id) {
                $refund->update(['status' => 'failed']);
            }
            report($exception);
            return response()->json(['message' => 'PayPal refund could not be confirmed. Check the provider transaction before retrying.'], 502);
        }
    }

    private function refundPayload(PaymentRefund $refund): array
    {
        return [
            'id' => $refund->id,
            'provider_refund_id' => $refund->provider_refund_id,
            'gross_minor' => $refund->gross_minor,
            'creator_reversal_minor' => $refund->creator_reversal_minor,
            'platform_reversal_minor' => $refund->platform_reversal_minor,
            'currency' => $refund->currency,
            'status' => $refund->status,
        ];
    }
}
