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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

    public function test_returned_paypal_payout_restores_available_balance_once_after_payment(): void
    {
        [$user, $profile] = $this->creatorWithAvailableBalance(12500);
        $payout = $this->requestedPayout($user, $profile, 12500);
        $payout->update([
            'status' => 'paid',
            'paypal_batch_id' => 'BATCH-RETURNED',
            'paypal_item_id' => 'ITEM-RETURNED',
        ]);

        $finalizer = app(CreatorPayoutFinalizer::class);
        $finalizer->returnFunds($payout, 'BATCH-RETURNED', 'ITEM-RETURNED', 'PayPal returned the payout');
        $finalizer->returnFunds($payout->fresh(), 'BATCH-RETURNED', 'ITEM-RETURNED', 'Duplicate return webhook');

        $available = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'available')->firstOrFail();
        $reserved = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'payout_reserved')->firstOrFail();

        $this->assertSame('returned', $payout->fresh()->status);
        $this->assertSame(12500, (int) CreatorLedgerEntry::where('ledger_account_id', $available->id)->sum('amount_minor'));
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $reserved->id)->sum('amount_minor'));
        $this->assertSame(1, CreatorLedgerEntry::where('idempotency_key', 'creator-payout-'.$payout->id.'-return-available')->count());
    }

    public function test_creator_cannot_request_a_payout_below_the_configured_minimum(): void
    {
        Config::set('marketplace.payout_minimum_minor', 5000);
        [$user, $profile] = $this->creatorWithAvailableBalance(4999);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CreatorPayoutRequestService::class)->request($profile, $user->id);
    }

    public function test_admin_can_submit_and_reconcile_a_paypal_payout(): void
    {
        Cache::flush();
        config([
            'paypal.mode' => 'sandbox',
            'paypal.client_id' => 'sandbox-client',
            'paypal.client_secret' => 'sandbox-secret',
        ]);
        [$creator, $profile] = $this->creatorWithAvailableBalance(12500);
        $payout = $this->requestedPayout($creator, $profile, 12500);

        $admin = User::create([
            'name' => 'Payout Admin',
            'email' => 'payout-admin@example.test',
            'password' => 'password-long-enough',
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'payout-access-token', 'expires_in' => 3600], 200),
            'https://api-m.sandbox.paypal.com/v1/payments/payouts' => Http::response([
                'batch_header' => ['payout_batch_id' => 'PAYPAL-BATCH-TEST', 'batch_status' => 'PENDING'],
            ], 201),
            'https://api-m.sandbox.paypal.com/v1/payments/payouts/PAYPAL-BATCH-TEST' => Http::response([
                'items' => [[
                    'payout_item' => [
                        'payout_item_id' => 'PAYPAL-ITEM-TEST',
                        'sender_item_id' => 'creator-payout-'.$payout->id,
                        'transaction_status' => 'SUCCESS',
                        'amount' => ['value' => '125.00', 'currency' => 'EUR'],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post('/admin/pagamentos/'.$payout->id.'/enviar')
            ->assertRedirect();

        $this->assertDatabaseHas('creator_payouts', [
            'id' => $payout->id,
            'status' => 'processing',
            'paypal_batch_id' => 'PAYPAL-BATCH-TEST',
            'paypal_sender_item_id' => 'creator-payout-'.$payout->id,
        ]);

        $this->post('/admin/pagamentos/'.$payout->id.'/reconciliar')->assertRedirect();

        $this->assertDatabaseHas('creator_payouts', [
            'id' => $payout->id,
            'status' => 'paid',
            'paypal_item_id' => 'PAYPAL-ITEM-TEST',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/payments/payouts')
            && $request->method() === 'POST'
            && $request->hasHeader('PayPal-Request-Id'));
    }

    public function test_verified_paypal_payout_webhook_settles_reserved_funds_once(): void
    {
        Cache::flush();
        config([
            'paypal.mode' => 'sandbox',
            'paypal.client_id' => 'sandbox-client',
            'paypal.client_secret' => 'sandbox-secret',
            'paypal.webhook_id' => 'PAYOUT-WEBHOOK-001',
        ]);
        [$creator, $profile] = $this->creatorWithAvailableBalance(12500);
        $payout = $this->requestedPayout($creator, $profile, 12500);
        $payout->update([
            'status' => 'processing',
            'paypal_batch_id' => 'PAYPAL-BATCH-WEBHOOK',
            'paypal_sender_item_id' => 'creator-payout-'.$payout->id,
        ]);

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'payout-webhook-token', 'expires_in' => 3600], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
        ]);

        $event = [
            'id' => 'PAYOUT-WH-EVENT-001',
            'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => [
                'id' => 'PAYPAL-ITEM-WEBHOOK',
                'payout_item_id' => 'PAYPAL-ITEM-WEBHOOK',
                'payout_batch_id' => 'PAYPAL-BATCH-WEBHOOK',
                'sender_item_id' => 'creator-payout-'.$payout->id,
                'transaction_status' => 'SUCCESS',
                'amount' => ['value' => '125.00', 'currency' => 'EUR'],
            ],
        ];
        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api-m.sandbox.paypal.com/cert/test',
            'PAYPAL-TRANSMISSION-ID' => 'PAYOUT-TRANSMISSION-001',
            'PAYPAL-TRANSMISSION-SIG' => 'signature',
            'PAYPAL-TRANSMISSION-TIME' => '2026-11-21T13:00:00Z',
        ];

        $this->postJson('/api/webhooks/paypal', $event, $headers)->assertOk()->assertJsonPath('status', 'processed');
        $this->postJson('/api/webhooks/paypal', $event, $headers)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('creator_payouts', ['id' => $payout->id, 'status' => 'paid']);
        $reserved = CreatorLedgerAccount::where('creator_profile_id', $profile->id)
            ->where('account_type', 'payout_reserved')->firstOrFail();
        $this->assertSame(0, (int) CreatorLedgerEntry::where('ledger_account_id', $reserved->id)->sum('amount_minor'));
        $this->assertDatabaseHas('paypal_webhook_events', [
            'provider_event_id' => 'PAYOUT-WH-EVENT-001',
            'processing_status' => 'processed',
        ]);
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
