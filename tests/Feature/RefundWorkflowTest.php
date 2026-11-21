<?php

namespace Tests\Feature;

use App\Models\CreatorLedgerEntry;
use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\FundHold;
use App\Models\MediaAccessGrant;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_full_refund_reverses_creator_net_and_revokes_entitlement(): void
    {
        [$buyer, $media, $order] = $this->capturedOrder('refund-full');
        $admin = User::create(['name' => 'Admin', 'email' => 'refund-admin@example.test', 'password' => 'password-long-enough']);
        $admin->forceFill(['is_admin' => true])->save();

        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/PAYPAL-CAPTURE-001/refund' => Http::response([
                'id' => 'PAYPAL-REFUND-FULL-001',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '12.50'],
            ], 201),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/orders/'.$order->order_reference.'/refunds', [
            'idempotency_key' => 'admin-full-refund-001',
            'note' => 'Approved full refund',
        ])->assertAccepted()
          ->assertJsonPath('status', 'completed')
          ->assertJsonPath('creator_reversal_minor', 1125)
          ->assertJsonPath('platform_reversal_minor', 125);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('payment_transactions', ['order_reference' => $order->order_reference, 'status' => 'refunded']);
        $this->assertDatabaseHas('payment_refunds', [
            'provider_refund_id' => 'PAYPAL-REFUND-FULL-001',
            'gross_minor' => 1250,
            'creator_reversal_minor' => 1125,
            'platform_reversal_minor' => 125,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('creator_ledger_entries', [
            'entry_type' => 'sale_refund_creator_reversal',
            'amount_minor' => -1125,
        ]);
        $this->assertDatabaseHas('fund_holds', ['status' => 'cancelled', 'amount_minor' => 0]);
        self::assertNotNull(MediaAccessGrant::where('buyer_id', $buyer->id)->where('digital_media_id', $media->id)->firstOrFail()->revoked_at);
        self::assertSame(2, CreatorLedgerEntry::count());
        self::assertSame(1, PaymentRefund::count());
    }

    public function test_partial_refund_uses_cumulative_proportional_creator_allocation_and_keeps_entitlement(): void
    {
        [$buyer, $media, $order] = $this->capturedOrder('refund-partial');
        $admin = User::create(['name' => 'Admin', 'email' => 'refund-partial-admin@example.test', 'password' => 'password-long-enough']);
        $admin->forceFill(['is_admin' => true])->save();

        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/PAYPAL-CAPTURE-001/refund' => Http::response([
                'id' => 'PAYPAL-REFUND-PARTIAL-001',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'EUR', 'value' => '3.75'],
            ], 201),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/orders/'.$order->order_reference.'/refunds', [
            'amount_minor' => 375,
            'idempotency_key' => 'admin-partial-refund-001',
        ])->assertAccepted()
          ->assertJsonPath('status', 'completed')
          ->assertJsonPath('creator_reversal_minor', 338)
          ->assertJsonPath('platform_reversal_minor', 37);

        $this->assertDatabaseHas('fund_holds', ['status' => 'pending', 'amount_minor' => 787]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'captured']);
        $this->assertDatabaseHas('creator_ledger_entries', [
            'entry_type' => 'sale_refund_creator_reversal',
            'amount_minor' => -338,
        ]);
        self::assertNull(MediaAccessGrant::where('buyer_id', $buyer->id)->where('digital_media_id', $media->id)->firstOrFail()->revoked_at);
    }

    private function capturedOrder(string $prefix): array
    {
        config([
            'paypal.mode' => 'sandbox',
            'paypal.client_id' => 'sandbox-client',
            'paypal.client_secret' => 'sandbox-secret',
            'paypal.return_url' => 'https://marketplace.example.test/paypal/return',
            'paypal.cancel_url' => 'https://marketplace.example.test/paypal/cancel',
            'marketplace.currency' => 'EUR',
            'marketplace.commission_bps' => 1000,
            'marketplace.hold_days' => 14,
        ]);

        $buyer = User::create(['name' => 'Buyer', 'email' => $prefix.'-buyer@example.test', 'password' => 'password-long-enough', 'account_type' => 'user']);
        $seller = User::create(['name' => 'Seller', 'email' => $prefix.'-seller@example.test', 'password' => 'password-long-enough', 'account_type' => 'creator']);
        $profile = CreatorProfile::create([
            'user_id' => $seller->id,
            'public_name' => 'Seller',
            'slug' => $prefix.'-seller',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Paid photo',
            'slug' => $prefix.'-photo',
            'visibility' => 'paid',
            'price_minor' => 1250,
            'currency' => 'EUR',
            'status' => 'published',
        ]);

        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-001',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-001']],
            ], 201),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-001/capture' => Http::response([
                'id' => 'PAYPAL-ORDER-001',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id' => 'PAYPAL-CAPTURE-001',
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'EUR', 'value' => '12.50'],
                    ]]],
                ]],
            ], 201),
        ]);

        Sanctum::actingAs($buyer);
        $checkout = $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => $prefix.'-checkout-idempotency',
        ])->assertCreated();
        $this->postJson('/api/orders/'.$checkout->json('order_reference').'/capture', [])->assertOk();

        return [$buyer, $media, Order::where('order_reference', $checkout->json('order_reference'))->firstOrFail()];
    }
}
