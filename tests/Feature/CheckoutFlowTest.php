<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\FundHold;
use App\Models\MediaAccessGrant;
use App\Models\Order;
use App\Models\PaypalWebhookEvent;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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
    }

    public function test_checkout_creates_one_paypal_order_with_a_frozen_commission_split(): void
    {
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-checkout@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPaidMedia();
        Sanctum::actingAs($buyer);

        $this->fakePayPal();

        $response = $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => 'buyer-checkout-key-001',
        ])->assertCreated()
          ->assertJsonPath('status', 'awaiting_approval')
          ->assertJsonPath('paypal_order_id', 'PAYPAL-ORDER-001')
          ->assertJsonPath('gross_minor', 1250)
          ->assertJsonPath('currency', 'EUR');

        $order = Order::where('order_reference', $response->json('order_reference'))->firstOrFail();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'unit_price_minor' => 1250,
            'commission_bps' => 1000,
            'commission_minor' => 125,
            'creator_net_minor' => 1125,
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'order_reference' => $order->order_reference,
            'provider_order_id' => 'PAYPAL-ORDER-001',
            'status' => 'pending',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/checkout/orders')
            && $request->hasHeader('PayPal-Request-Id'));
    }

    public function test_checkout_idempotency_returns_the_existing_paypal_order(): void
    {
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-idempotent@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPaidMedia();
        Sanctum::actingAs($buyer);
        $this->fakePayPal();

        $headers = ['Idempotency-Key' => 'same-checkout-key-002'];
        $first = $this->postJson('/api/media/'.$media->slug.'/checkout', [], $headers)->assertCreated();
        $second = $this->postJson('/api/media/'.$media->slug.'/checkout', [], $headers)->assertOk();

        self::assertSame($first->json('order_reference'), $second->json('order_reference'));
        self::assertSame(1, Order::count());
        Http::assertSentCount(2);
    }

    public function test_completed_capture_grants_access_and_places_creator_net_on_hold_once(): void
    {
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-capture@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPaidMedia();
        Sanctum::actingAs($buyer);
        $this->fakePayPal(withCapture: true);

        $checkout = $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => 'capture-checkout-key-003',
        ])->assertCreated();

        $reference = $checkout->json('order_reference');

        $this->postJson('/api/orders/'.$reference.'/capture', [])->assertOk()->assertJsonPath('status', 'captured');
        $this->postJson('/api/orders/'.$reference.'/capture', [])->assertOk()->assertJsonPath('status', 'captured');

        $this->assertDatabaseHas('orders', ['order_reference' => $reference, 'status' => 'captured']);
        $this->assertDatabaseHas('payment_transactions', [
            'order_reference' => $reference,
            'provider_capture_id' => 'PAYPAL-CAPTURE-001',
            'status' => 'captured',
        ]);
        $this->assertDatabaseHas('media_access_grants', [
            'buyer_id' => $buyer->id,
            'digital_media_id' => $media->id,
            'grant_type' => 'paid',
        ]);
        $this->assertDatabaseHas('creator_ledger_entries', [
            'entry_type' => 'sale_credit_pending',
            'amount_minor' => 1125,
            'currency' => 'EUR',
        ]);
        self::assertSame(1, MediaAccessGrant::count());
        self::assertSame(1, FundHold::count());
        Http::assertSentCount(3);
    }

    public function test_paypal_amount_mismatch_never_grants_media_access(): void
    {
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-mismatch@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPaidMedia();
        Sanctum::actingAs($buyer);
        $this->fakePayPal(withCapture: true, captureAmount: '12.49');

        $checkout = $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => 'mismatch-checkout-key-004',
        ])->assertCreated();

        $this->postJson('/api/orders/'.$checkout->json('order_reference').'/capture', [])->assertStatus(409);

        $this->assertDatabaseCount('media_access_grants', 0);
        $this->assertDatabaseCount('creator_ledger_entries', 0);
    }

    public function test_verified_paypal_webhook_finalizes_capture_once(): void
    {
        $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer-webhook@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPaidMedia();
        Sanctum::actingAs($buyer);
        config(['paypal.webhook_id' => 'WEBHOOK-ID-001']);
        $this->fakePayPal();

        $checkout = $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => 'webhook-checkout-key-006',
        ])->assertCreated();

        $event = [
            'id' => 'WH-EVENT-001',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'PAYPAL-CAPTURE-001',
                'amount' => ['currency_code' => 'EUR', 'value' => '12.50'],
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'PAYPAL-ORDER-001'],
                ],
            ],
        ];
        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api-m.sandbox.paypal.com/cert/test',
            'PAYPAL-TRANSMISSION-ID' => 'TRANSMISSION-001',
            'PAYPAL-TRANSMISSION-SIG' => 'signature',
            'PAYPAL-TRANSMISSION-TIME' => '2026-11-21T12:00:00Z',
        ];

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $this->postJson('/api/webhooks/paypal', $event, $headers)->assertOk()->assertJsonPath('status', 'processed');
        $this->postJson('/api/webhooks/paypal', $event, $headers)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('orders', [
            'order_reference' => $checkout->json('order_reference'),
            'status' => 'captured',
        ]);
        $this->assertDatabaseHas('paypal_webhook_events', [
            'provider_event_id' => 'WH-EVENT-001',
            'processing_status' => 'processed',
        ]);
        $this->assertDatabaseCount('media_access_grants', 1);
        $this->assertDatabaseCount('creator_ledger_entries', 1);
        $this->assertDatabaseCount('fund_holds', 1);
        self::assertSame(1, PaypalWebhookEvent::count());
    }

    public function test_creator_cannot_purchase_own_paid_media(): void
    {
        $creator = User::create(['name' => 'Seller', 'email' => 'seller-self@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $creator->id,
            'public_name' => 'Seller',
            'slug' => 'seller-self',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Seller content',
            'slug' => 'seller-own-paid-media',
            'visibility' => 'paid',
            'price_minor' => 1250,
            'currency' => 'EUR',
            'status' => 'published',
        ]);

        Sanctum::actingAs($creator);
        $this->postJson('/api/media/'.$media->slug.'/checkout', [], [
            'Idempotency-Key' => 'self-purchase-key-005',
        ])->assertForbidden();
    }

    public function test_paypal_full_refund_uses_capture_endpoint_and_idempotency_header(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'sandbox-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-REFUND-001/refund' => Http::response([
                'id' => 'REFUND-001',
                'status' => 'COMPLETED',
            ], 201),
        ]);

        $result = app(\App\Services\Payments\PayPalClient::class)->refundCapture(
            'CAPTURE-REFUND-001',
            null,
            'EUR',
            'refund-full-idempotency-001',
            'Approved refund'
        );

        self::assertSame('REFUND-001', $result['id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/payments/captures/CAPTURE-REFUND-001/refund')
            && $request->method() === 'POST'
            && $request->hasHeader('PayPal-Request-Id', 'refund-full-idempotency-001')
            && $request['note_to_payer'] === 'Approved refund'
            && ! isset($request['amount']));
    }

    public function test_paypal_partial_refund_sends_minor_unit_amount_and_currency(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'sandbox-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-REFUND-002/refund' => Http::response([
                'id' => 'REFUND-002',
                'status' => 'COMPLETED',
            ], 201),
        ]);

        app(\App\Services\Payments\PayPalClient::class)->refundCapture(
            'CAPTURE-REFUND-002',
            375,
            'eur',
            'refund-partial-idempotency-002'
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/payments/captures/CAPTURE-REFUND-002/refund')
            && $request['amount']['currency_code'] === 'EUR'
            && $request['amount']['value'] === '3.75'
            && $request->hasHeader('PayPal-Request-Id', 'refund-partial-idempotency-002'));
    }

    private function createPaidMedia(): DigitalMedia
    {
        $seller = User::create(['name' => 'Seller', 'email' => 'seller-'.uniqid().'@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $seller->id,
            'public_name' => 'Seller',
            'slug' => 'seller-'.uniqid(),
            'status' => 'approved',
        ]);

        return DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Paid photo',
            'slug' => 'paid-media-'.uniqid(),
            'visibility' => 'paid',
            'price_minor' => 1250,
            'currency' => 'EUR',
            'status' => 'published',
        ]);
    }

    private function fakePayPal(bool $withCapture = false, string $captureAmount = '12.50'): void
    {
        $responses = [
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'sandbox-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-001',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-001'],
                ],
            ], 201),
        ];

        if ($withCapture) {
            $responses['https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-001/capture'] = Http::response([
                'id' => 'PAYPAL-ORDER-001',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [[
                            'id' => 'PAYPAL-CAPTURE-001',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'EUR', 'value' => $captureAmount],
                        ]],
                    ],
                ]],
            ], 201);
        }

        Http::fake($responses);
    }
}
