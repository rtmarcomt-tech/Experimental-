<?php

namespace App\Services\Payments;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\FundHold;
use App\Models\MediaAccessGrant;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CaptureFinalizer
{
    public function finalize(string $paypalOrderId, string $captureId, int $grossMinor, string $currency): Order
    {
        $payment = PaymentTransaction::where('provider_order_id', $paypalOrderId)->firstOrFail();
        $order = Order::where('order_reference', $payment->order_reference)->firstOrFail();

        if ($order->gross_minor !== $grossMinor || $order->currency !== strtoupper($currency)) {
            throw new RuntimeException('The verified PayPal capture does not match the stored order amount.');
        }

        return DB::transaction(function () use ($order, $payment, $captureId): Order {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === 'captured') {
                return $lockedOrder;
            }

            $item = $lockedOrder->item()->firstOrFail();
            $lockedPayment = PaymentTransaction::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $now = now();

            $lockedPayment->update([
                'provider_capture_id' => $captureId,
                'status' => 'captured',
                'captured_at' => $now,
            ]);

            $grant = MediaAccessGrant::where('buyer_id', $lockedOrder->buyer_id)
                ->where('digital_media_id', $item->digital_media_id)
                ->lockForUpdate()
                ->first();

            if (! $grant) {
                MediaAccessGrant::create([
                    'buyer_id' => $lockedOrder->buyer_id,
                    'digital_media_id' => $item->digital_media_id,
                    'payment_transaction_id' => $lockedPayment->id,
                    'grant_type' => 'paid',
                    'granted_at' => $now,
                ]);
            } elseif ($grant->revoked_at !== null) {
                $grant->update([
                    'payment_transaction_id' => $lockedPayment->id,
                    'grant_type' => 'paid',
                    'granted_at' => $now,
                    'revoked_at' => null,
                    'revocation_reason' => null,
                ]);
            }

            if ($item->creator_net_minor > 0) {
                $account = CreatorLedgerAccount::firstOrCreate([
                    'creator_profile_id' => $item->creator_profile_id,
                    'currency' => $item->currency,
                    'account_type' => 'pending',
                ]);

                $entry = CreatorLedgerEntry::firstOrCreate(
                    ['idempotency_key' => 'sale-'.$lockedPayment->id.'-creator-net'],
                    [
                        'ledger_account_id' => $account->id,
                        'payment_transaction_id' => $lockedPayment->id,
                        'entry_type' => 'sale_credit_pending',
                        'amount_minor' => $item->creator_net_minor,
                        'currency' => $item->currency,
                        'reference_type' => 'order',
                        'reference_id' => $lockedOrder->order_reference,
                        'effective_at' => $now,
                    ]
                );

                FundHold::firstOrCreate(
                    ['ledger_entry_id' => $entry->id],
                    [
                        'amount_minor' => $item->creator_net_minor,
                        'currency' => $item->currency,
                        'release_at' => $now->copy()->addDays((int) config('marketplace.hold_days', 14)),
                        'status' => 'pending',
                        'reason' => 'refund_dispute_window',
                    ]
                );
            }

            $lockedOrder->update(['status' => 'captured']);

            return $lockedOrder->fresh();
        });
    }
}
