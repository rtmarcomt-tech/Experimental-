<?php

namespace Tests\Feature;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\CreatorProfile;
use App\Models\FundHold;
use App\Models\User;
use App\Services\Finance\HoldReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_matured_hold_moves_creator_net_to_available_once(): void
    {
        $user = User::create(['name' => 'Creator', 'email' => 'hold-release@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Creator', 'slug' => 'hold-release-creator', 'status' => 'approved',
        ]);
        $pending = CreatorLedgerAccount::create([
            'creator_profile_id' => $profile->id, 'currency' => 'EUR', 'account_type' => 'pending',
        ]);
        $sale = CreatorLedgerEntry::create([
            'ledger_account_id' => $pending->id,
            'entry_type' => 'sale_credit_pending',
            'amount_minor' => 1125,
            'currency' => 'EUR',
            'idempotency_key' => 'hold-release-test-sale',
            'effective_at' => now(),
        ]);
        $hold = FundHold::create([
            'ledger_entry_id' => $sale->id,
            'amount_minor' => 1125,
            'currency' => 'EUR',
            'release_at' => now()->subMinute(),
            'status' => 'pending',
            'reason' => 'refund_dispute_window',
        ]);

        $service = app(HoldReleaseService::class);
        self::assertSame(1, $service->releaseMatured());
        self::assertSame(0, $service->releaseMatured());

        $this->assertDatabaseHas('fund_holds', ['id' => $hold->id, 'status' => 'released']);
        $this->assertDatabaseHas('creator_ledger_accounts', [
            'creator_profile_id' => $profile->id, 'currency' => 'EUR', 'account_type' => 'available',
        ]);
        $this->assertDatabaseHas('creator_ledger_entries', [
            'entry_type' => 'hold_release_pending_debit', 'amount_minor' => -1125,
        ]);
        $this->assertDatabaseHas('creator_ledger_entries', [
            'entry_type' => 'hold_release_available_credit', 'amount_minor' => 1125,
        ]);
        self::assertSame(3, CreatorLedgerEntry::count());
    }
}
