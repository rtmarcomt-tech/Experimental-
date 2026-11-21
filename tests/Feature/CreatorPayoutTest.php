<?php

namespace Tests\Feature;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\CreatorPayout;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Payments\CreatorPayoutFinalizer;
use App\Services\Payments\CreatorPayoutRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CreatorPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_request_reserves_the_available_balance_in_the_ledger(): void
    {
        Config::set('marketplace.payout_minimum_minor', 5000);
        [$user, $profile, $available] = $this->creatorWithAvailableBalance(12500);

        $payout = app(CreatorPayoutRequestService::class)->request($profile, $user->id);

        $this->assertSame('requested', $payout->status);
        $this->assertSame(12500, $payout->amount_minor);
        $this->assertSame('creator-payments@example.test', $payout->recipient_email);
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $available->id)->sum('amount_minor'));

        $reserved = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'payout_reserved')->firstOrFail();
        $this->assertSame(12500, (int) CreatorLedgerEntry::where('ledger_account_id', $reserved->id)->sum('amount_minor'));
    }

    public function test_failed_payout_restores_the_reserved_balance_exactly_once(): void
    {
        [$user, $profile] = $this->creatorWithAvailableBalance(12500);
        $payout = $this->requestedPayout($user, $profile, 12500);
        $payout->update(['status' => 'processing', 'paypal_batch_id' => 'BATCH-FAILED']);

        $finalizer = app(CreatorPayoutFinalizer::class);
        $finalizer->fail($payout, 'BATCH-FAILED', 'ITEM-FAILED', 'Recipient account rejected payment');
        $finalizer->fail($payout->fresh(), 'BATCH-FAILED', 'ITEM-FAILED', 'Duplicate webhook');

        $available = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'available')->firstOrFail();
        $reserved = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'payout_reserved')->firstOrFail();

        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertSame(12500, (int) CreatorLedgerEntry::where('ledger_account_id', $available->id)->sum('amount_minor'));
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $reserved->id)->sum('amount_minor'));
        $this->assertSame(2, CreatorLedgerEntry::where('reference_id', (string) $payout->id)->count());
    }

    public function test_successful_payout_consumes_reserved_balance_without_restoring_available_funds(): void
    {
        [$user, $profile] = $this->creatorWithAvailableBalance(12500);
        $payout = $this->requestedPayout($user, $profile, 12500);
        $payout->update(['status' => 'processing', 'paypal_batch_id' => 'BATCH-PAID']);

        app(CreatorPayoutFinalizer::class)->complete($payout, 'BATCH-PAID', 'ITEM-PAID');

        $available = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'available')->firstOrFail();
        $reserved = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'payout_reserved')->firstOrFail();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $available->id)->sum('amount_minor'));
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $reserved->id)->sum('amount_minor'));
    }

    public function test_creator_cannot_request_a_payout_below_the_configured_minimum(): void
    {
        Config::set('marketplace.payout_minimum_minor', 5000);
        [$user, $profile] = $this->creatorWithAvailableBalance(4999);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CreatorPayoutRequestService::class)->request($profile, $user->id);
    }

    private function creatorWithAvailableBalance(int $amount): array
    {
        $user = User::create([
            'name' => 'Payout Creator',
            'email' => 'creator-payout-test@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $user->id,
            'public_name' => 'Payout Creator',
            'slug' => 'payout-creator',
            'status' => 'approved',
            'payout_email' => 'creator-payments@example.test',
        ]);
        $available = CreatorLedgerAccount::create([
            'creator_profile_id' => $profile->id,
            'currency' => 'EUR',
            'account_type' => 'available',
        ]);
        CreatorLedgerEntry::create([
            'ledger_account_id' => $available->id,
            'entry_type' => 'sale_credit_available',
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'idempotency_key' => 'creator-payout-test-credit',
            'effective_at' => now(),
        ]);

        return [$user, $profile, $available];
    }

    private function requestedPayout(User $user, CreatorProfile $profile, int $amount): CreatorPayout
    {
        $available = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'available')->firstOrFail();
        $reserved = CreatorLedgerAccount::create([
            'creator_profile_id' => $profile->id,
            'currency' => 'EUR',
            'account_type' => 'payout_reserved',
        ]);
        CreatorLedgerEntry::create([
            'ledger_account_id' => $available->id,
            'entry_type' => 'payout_reservation',
            'amount_minor' => -$amount,
            'currency' => 'EUR',
            'idempotency_key' => 'test-payout-reserve-available',
            'effective_at' => now(),
        ]);
        CreatorLedgerEntry::create([
            'ledger_account_id' => $reserved->id,
            'entry_type' => 'payout_reservation',
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'idempotency_key' => 'test-payout-reserve-held',
            'effective_at' => now(),
        ]);

        return CreatorPayout::create([
            'creator_profile_id' => $profile->id,
            'requested_by' => $user->id,
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'recipient_email' => $profile->payout_email,
            'status' => 'requested',
            'idempotency_key' => 'test-payout-'.$profile->id,
            'requested_at' => now(),
        ]);
    }
}
