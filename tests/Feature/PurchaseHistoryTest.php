<?php

namespace Tests\\Feature;

use App\\Models\\CreatorProfile;
use App\\Models\\DigitalMedia;
use App\\Models\\MediaAccessGrant;
use App\\Models\\Order;
use App\\Models\\OrderItem;
use App\\Models\\PaymentTransaction;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class PurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_view_own_purchase_history_and_order_detail(): void
    {
        [$buyer, $order, $media, $payment] = $this->createCapturedPurchase('history-owner');
        MediaAccessGrant::create([
            'buyer_id' => $buyer->id,
            'digital_media_id' => $media->id,
            'payment_transaction_id' => $payment->id,
            'grant_type' => 'paid',
            'granted_at' => now(),
        ]);

        $this->actingAs($buyer)->get('/compras')->assertOk()
            ->assertSee($order->order_reference)
            ->assertSee('Editorial Photo');

        $this->get('/compras/'.$order->order_reference)->assertOk()
            ->assertSee('Pagamento confirmado')
            ->assertSee('Transferir conteúdo');
    }

    public function test_buyer_cannot_view_another_users_order(): void
    {
        [, $order] = $this->createCapturedPurchase('history-private');
        $other = User::create([
            'name' => 'Other Buyer',
            'email' => 'history-other@example.test',
            'password' => 'password-long-enough',
        ]);

        $this->actingAs($other)->get('/compras/'.$order->order_reference)->assertNotFound();
    }

    private function createCapturedPurchase(string $prefix): array
    {
        $buyer = User::create([
            'name' => 'Purchase Buyer',
            'email' => $prefix.'-buyer@example.test',
            'password' => 'password-long-enough',
        ]);
        $seller = User::create([
            'name' => 'Purchase Seller',
            'email' => $prefix.'-seller@example.test',
            'password' => 'password-long-enough',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $seller->id,
            'public_name' => 'Studio Seller',
            'slug' => $prefix.'-seller',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Editorial Photo',
            'slug' => $prefix.'-photo',
            'visibility' => 'paid',
            'price_minor' => 2500,
            'currency' => 'EUR',
            'status' => 'published',
        ]);
        $reference = strtoupper($prefix).'-ORDER';
        $order = Order::create([
            'buyer_id' => $buyer->id,
            'order_reference' => $reference,
            'status' => 'captured',
            'currency' => 'EUR',
            'gross_minor' => 2500,
            'payment_provider' => 'paypal',
            'idempotency_key' => $prefix.'-order-key',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'digital_media_id' => $media->id,
            'creator_profile_id' => $profile->id,
            'title_snapshot' => 'Editorial Photo',
            'unit_price_minor' => 2500,
            'currency' => 'EUR',
            'commission_bps' => 1000,
            'commission_minor' => 250,
            'creator_net_minor' => 2250,
        ]);
        $payment = PaymentTransaction::create([
            'provider' => 'paypal',
            'provider_order_id' => $prefix.'-paypal-order',
            'provider_capture_id' => $prefix.'-paypal-capture',
            'order_reference' => $reference,
            'gross_minor' => 2500,
            'currency' => 'EUR',
            'status' => 'captured',
            'idempotency_key' => $prefix.'-payment-key',
            'captured_at' => now(),
        ]);

        return [$buyer, $order, $media, $payment];
    }
}
