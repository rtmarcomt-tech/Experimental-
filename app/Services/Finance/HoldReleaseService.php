<?php

namespace App\Services\Finance;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\FundHold;
use Illuminate\Support\Facades\DB;

class HoldReleaseService
{
    public function releaseMatured(): int
    {
        $released = 0;

        FundHold::query()
            ->where('status', 'pending')
            ->where('release_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($holds) use (&$released): void {
                foreach ($holds as $candidate) {
                    $didRelease = DB::transaction(function () use ($candidate): bool {
                        $hold = FundHold::whereKey($candidate->id)->lockForUpdate()->first();

                        if (! $hold || $hold->status !== 'pending' || $hold->release_at->isFuture()) {
                            return false;
                        }

                        $sourceEntry = $hold->ledgerEntry()->firstOrFail();
                        $pendingAccount = CreatorLedgerAccount::whereKey($sourceEntry->ledger_account_id)->firstOrFail();

                        if ($pendingAccount->account_type !== 'pending') {
                            throw new \RuntimeException('A fund hold must reference a pending creator ledger account.');
                        }

                        $availableAccount = CreatorLedgerAccount::firstOrCreate([
                            'creator_profile_id' => $pendingAccount->creator_profile_id,
                            'currency' => $hold->currency,
                            'account_type' => 'available',
                        ]);

                        $now = now();
                        $reference = (string) $hold->id;

                        CreatorLedgerEntry::firstOrCreate(
                            ['idempotency_key' => 'hold-'.$hold->id.'-release-pending-debit'],
                            [
                                'ledger_account_id' => $pendingAccount->id,
                                'payment_transaction_id' => $sourceEntry->payment_transaction_id,
                                'entry_type' => 'hold_release_pending_debit',
                                'amount_minor' => -$hold->amount_minor,
                                'currency' => $hold->currency,
                                'reference_type' => 'fund_hold',
                                'reference_id' => $reference,
                                'effective_at' => $now,
                            ]
                        );

                        CreatorLedgerEntry::firstOrCreate(
                            ['idempotency_key' => 'hold-'.$hold->id.'-release-available-credit'],
                            [
                                'ledger_account_id' => $availableAccount->id,
                                'payment_transaction_id' => $sourceEntry->payment_transaction_id,
                                'entry_type' => 'hold_release_available_credit',
                                'amount_minor' => $hold->amount_minor,
                                'currency' => $hold->currency,
                                'reference_type' => 'fund_hold',
                                'reference_id' => $reference,
                                'effective_at' => $now,
                            ]
                        );

                        $hold->update(['status' => 'released', 'released_at' => $now]);

                        return true;
                    });

                    if ($didRelease) {
                        $released++;
                    }
                }
            });

        return $released;
    }
}
