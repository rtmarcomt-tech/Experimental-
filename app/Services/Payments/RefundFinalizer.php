<?php

namespace App\\Services\\Payments;

use App\\Models\\CreatorLedgerAccount;
use App\\Models\\CreatorLedgerEntry;
use App\\Models\\FundHold;
use App\\Models\\MediaAccessGrant;
use App\\Models\\Order;
use App\\Models\\PaymentRefund;
use App\\Models\\PaymentTransaction;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

class RefundFinalizer
{
    public function finalize(PaymentRefund $refund, string $providerRefundId, int $grossMinor, string $currency): PaymentRefund
    {
        return DB::transaction(function () use ($refund, $providerRefundId, $grossMinor, $currency): PaymentRefund {
            $lockedRefund = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $payment = PaymentTransaction::whereKey($lockedRefund->payment_transaction_id)->lockForUpdate()->firstOrFail();

            if ($lockedRefund->status === 'completed') {
                return $lockedRefund;
            }

            if ($grossMinor !== $lockedRefund->gross_minor || strtoupper($currency) !== $lockedRefund->currency) {
                throw new RuntimeException('The verified PayPal refund does not match the stored refund request.');
            }

            $order = Order::where('order_reference', $payment->order_reference)->firstOrFail();
            $item = $order->item()->firstOrFail();
            $completedBefore = PaymentRefund::where('payment_transaction_id', $payment->id)
                ->where('status', 'completed')
                ->whereKeyNot($lockedRefund->id)
                ->sum('gross_minor');

            if ($completedBefore + $grossMinor > $payment->gross_minor) {
                throw new RuntimeException('Cumulative refunds cannot exceed the captured payment.');
            }

            $cumulativeCreatorTarget = intdiv(
                (($completedBefore + $grossMinor) * $item->creator_net_minor) + intdiv($payment->gross_minor, 2),
                $payment->gross_minor
            );
            $creatorAlreadyReversed = PaymentRefund::where('payment_transaction_id', $payment->id)
                ->where('status', 'completed')
                ->whereKeyNot($lockedRefund->id)
                ->sum('creator_reversal_minor');
            $creatorReversal = $cumulativeCreatorTarget - $creatorAlreadyReversed;

            if ($creatorReversal < 0 || $creatorReversal > $item->creator_net_minor - $creatorAlreadyReversed) {
                throw new RuntimeException('Calculated creator refund allocation is outside the original creator proceeds.');
            }

            if ($creatorReversal > 0) {
                $hold = FundHold::whereHas('ledgerEntry', fn ($query) => $query
                    ->where('payment_transaction_id', $payment->id))
                    ->lockForUpdate()
                    ->first();

                $accountType = $hold && $hold->status === 'released' ? 'available' : 'pending';
                $account = CreatorLedgerAccount::firstOrCreate([
                    'creator_profile_id' => $item->creator_profile_id,
                    'currency' => $item->currency,
                    'account_type' => $accountType,
                ]);

                CreatorLedgerEntry::firstOrCreate(
                    ['idempotency_key' => 'refund-'.$lockedRefund->id.'-creator-reversal'],
                    [
                        'ledger_account_id' => $account->id,
                        'payment_transaction_id' => $payment->id,
                        'entry_type' => 'sale_refund_creator_reversal',
                        'amount_minor' => -$creatorReversal,
                        'currency' => $item->currency,
                        'reference_type' => 'payment_refund',
                        'reference_id' => (string) $lockedRefund->id,
                        'effective_at' => now(),
                    ]
                );

                if ($hold && $hold->status === 'pending') {
                    $remaining = max(0, $hold->amount_minor - $creatorReversal);
                    $hold->update([
                        'amount_minor' => $remaining,
                        'status' => $remaining === 0 ? 'cancelled' : 'pending',
                    ]);
                }
            }

            $platformReversal = $grossMinor - $creatorReversal;
            $lockedRefund->update([
                'provider_refund_id' => $providerRefundId,
                'creator_reversal_minor' => $creatorReversal,
                'platform_reversal_minor' => $platformReversal,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($completedBefore + $grossMinor === $payment->gross_minor) {
                MediaAccessGrant::where('payment_transaction_id', $payment->id)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'revocation_reason' => 'payment_refunded',
                    ]);
                $order->update(['status' => 'refunded']);
                $payment->update(['status' => 'refunded']);
            }

            return $lockedRefund->fresh();
        });
    }
}
