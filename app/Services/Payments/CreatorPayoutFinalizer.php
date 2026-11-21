<?php

namespace App\Services\Payments;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\CreatorPayout;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreatorPayoutFinalizer
{
    public function complete(CreatorPayout $payout, string $batchId, ?string $itemId = null): CreatorPayout
    {
        return $this->settle($payout, 'paid', $batchId, $itemId, null);
    }

    public function fail(CreatorPayout $payout, string $batchId, ?string $itemId, string $reason): CreatorPayout
    {
        return $this->settle($payout, 'failed', $batchId, $itemId, mb_substr($reason, 0, 4000));
    }

    private function settle(CreatorPayout $payout, string $status, string $batchId, ?string $itemId, ?string $reason): CreatorPayout
    {
        return DB::transaction(function () use ($payout, $status, $batchId, $itemId, $reason): CreatorPayout {
            $locked = CreatorPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['paid', 'failed'], true)) {
                if ($locked->status !== $status) {
                    throw new RuntimeException('A settled creator payout cannot change its final status.');
                }
                return $locked;
            }

            if (! in_array($locked->status, ['processing', 'submission_unknown'], true)) {
                throw new RuntimeException('Only submitted or uncertain payouts can be settled.');
            }

            if ($batchId === '' || ($locked->paypal_batch_id && $locked->paypal_batch_id !== $batchId)) {
                throw new RuntimeException('The PayPal payout batch does not match the stored payout request.');
            }

            $reserved = CreatorLedgerAccount::where([
                'creator_profile_id' => $locked->creator_profile_id,
                'currency' => $locked->currency,
                'account_type' => 'payout_reserved',
            ])->firstOrFail();

            CreatorLedgerEntry::firstOrCreate(
                ['idempotency_key' => 'creator-payout-'.$locked->id.'-settlement'],
                [
                    'ledger_account_id' => $reserved->id,
                    'entry_type' => $status === 'paid' ? 'payout_completed' : 'payout_failed_release',
                    'amount_minor' => -$locked->amount_minor,
                    'currency' => $locked->currency,
                    'reference_type' => 'creator_payout',
                    'reference_id' => (string) $locked->id,
                    'idempotency_key' => 'creator-payout-'.$locked->id.'-settlement',
                    'effective_at' => now(),
                ]
            );

            if ($status === 'failed') {
                $available = CreatorLedgerAccount::firstOrCreate([
                    'creator_profile_id' => $locked->creator_profile_id,
                    'currency' => $locked->currency,
                    'account_type' => 'available',
                ]);

                CreatorLedgerEntry::firstOrCreate(
                    ['idempotency_key' => 'creator-payout-'.$locked->id.'-restore'],
                    [
                        'ledger_account_id' => $available->id,
                        'entry_type' => 'payout_restored',
                        'amount_minor' => $locked->amount_minor,
                        'currency' => $locked->currency,
                        'reference_type' => 'creator_payout',
                        'reference_id' => (string) $locked->id,
                        'idempotency_key' => 'creator-payout-'.$locked->id.'-restore',
                        'effective_at' => now(),
                    ]
                );
            }

            $locked->update([
                'status' => $status,
                'paypal_batch_id' => $batchId,
                'paypal_item_id' => $itemId ?: $locked->paypal_item_id,
                'failure_reason' => $reason,
                'completed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
