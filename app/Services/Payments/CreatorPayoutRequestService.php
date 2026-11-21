<?php

namespace App\\Services\\Payments;

use App\\Models\\CreatorLedgerAccount;
use App\\Models\\CreatorLedgerEntry;
use App\\Models\\CreatorPayout;
use App\\Models\\CreatorProfile;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;

class CreatorPayoutRequestService
{
    public function request(CreatorProfile $profile, int $userId): CreatorPayout
    {
        return DB::transaction(function () use ($profile, $userId): CreatorPayout {
            $lockedProfile = CreatorProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if (! $lockedProfile->canSell()) {
                throw ValidationException::withMessages(['payout' => ['A conta de criador não está ativa e aprovada.']]);
            }

            if (! filter_var($lockedProfile->payout_email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['payout_email' => ['Define primeiro um endereço de email PayPal válido.']]);
            }

            $active = CreatorPayout::where('creator_profile_id', $lockedProfile->id)
                ->whereIn('status', ['requested', 'processing', 'submission_unknown'])
                ->exists();

            if ($active) {
                throw ValidationException::withMessages(['payout' => ['Já existe um pedido de pagamento em curso.']]);
            }

            $currency = strtoupper((string) config('marketplace.currency', 'EUR'));
            $available = CreatorLedgerAccount::firstOrCreate([
                'creator_profile_id' => $lockedProfile->id,
                'currency' => $currency,
                'account_type' => 'available',
            ]);
            $available = CreatorLedgerAccount::whereKey($available->id)->lockForUpdate()->firstOrFail();
            $balance = (int) CreatorLedgerEntry::where('ledger_account_id', $available->id)->sum('amount_minor');
            $minimum = (int) config('marketplace.payout_minimum_minor', 5000);

            if ($balance < $minimum) {
                throw ValidationException::withMessages([
                    'payout' => ['O saldo disponível ainda não atingiu o mínimo de '.number_format($minimum / 100, 2, ',', '.').' '.$currency.'.'],
                ]);
            }

            $reserved = CreatorLedgerAccount::firstOrCreate([
                'creator_profile_id' => $lockedProfile->id,
                'currency' => $currency,
                'account_type' => 'payout_reserved',
            ]);

            $payout = CreatorPayout::create([
                'creator_profile_id' => $lockedProfile->id,
                'requested_by' => $userId,
                'amount_minor' => $balance,
                'currency' => $currency,
                'recipient_email' => $lockedProfile->payout_email,
                'status' => 'requested',
                'idempotency_key' => 'creator-payout-request-'.$lockedProfile->id.'-'.Str::uuid(),
                'requested_at' => now(),
            ]);

            $key = 'creator-payout-'.$payout->id.'-reserve';
            CreatorLedgerEntry::create([
                'ledger_account_id' => $available->id,
                'entry_type' => 'payout_reservation',
                'amount_minor' => -$balance,
                'currency' => $currency,
                'reference_type' => 'creator_payout',
                'reference_id' => (string) $payout->id,
                'idempotency_key' => $key.'-available',
                'effective_at' => now(),
            ]);
            CreatorLedgerEntry::create([
                'ledger_account_id' => $reserved->id,
                'entry_type' => 'payout_reservation',
                'amount_minor' => $balance,
                'currency' => $currency,
                'reference_type' => 'creator_payout',
                'reference_id' => (string) $payout->id,
                'idempotency_key' => $key.'-reserved',
                'effective_at' => now(),
            ]);

            return $payout;
        });
    }
}
