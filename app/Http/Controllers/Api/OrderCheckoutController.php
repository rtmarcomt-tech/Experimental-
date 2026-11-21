<?php

namespace App\Http\Controllers\Api;

use App\Domain\Finance\CommissionCalculator;
use App\Http\Controllers\Controller;
use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\DigitalMedia;
use App\Models\FundHold;
use App\Models\MediaAccessGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Services\Payments\PayPalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderCheckoutController extends Controller
{
    public function create(Request $request, string $slug, PayPalClient $paypal, CommissionCalculator $calculator): JsonResponse
    {
        $buyer = $request->user();
        $rawKey = $request->header('Idempotency-Key');

        if (! is_string($rawKey) || strlen($rawKey) < 8 || strlen($rawKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A unique Idempotency-Key header between 8 and 100 characters is required.'],
            ]);
        }

        $idempotencyKey = hash('sha256', $buyer->id.'|'.$rawKey);
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            abort_unless($existing->buyer_id === $buyer->id, 409);
            return $this->checkoutResponse($existing, 200);
        }

        $media = DigitalMedia::where('slug', $slug)
            ->where('status', 'published')
            ->where('visibility', 'paid')
            ->with('creatorProfile.user')
            ->firstOrFail();

        abort_if($media->creator_profile_id === $buyer->creatorProfile?->id, 403, 'Creators cannot purchase their own media.');
        abort_unless($media->creatorProfile?->canSell(), 409, 'This creator is not currently eligible to sell.');

        if (MediaAccessGrant::where('buyer_id', $buyer->id)->where('digital_media_id', $media->id)->whereNull('revoked_at')->exists()) {
            return response()->json(['message' => 'This media is already available in your library.'], 409);
        }

        $currency = strtoupper($media->currency);
        if ($currency !== config('marketplace.currency', 'EUR')) {
            abort(422, 'This checkout currency is not enabled.');
        }

        $gross = (int) $media->price_minor;
        $commissionBps = (int) config('marketplace.commission_bps', 1000);
        $split = $calculator->split($gross, $commissionBps);
        $reference = (string) Str::uuid();
        $paymentKey = hash('sha256', $idempotencyKey.'|payment');

        $order = DB::transaction(function () use ($buyer, $media, $currency, $gross, $commissionBps, $split, $idempotencyKey, $reference, $paymentKey): Order {
            $order = Order::create([
                'buyer_id' => $buyer->id,
                'order_reference' => $reference,
                'status' => 'creating',
                'currency' => $currency,
                'gross_minor' => $gross,
                'payment_provider' => 'paypal',
                'idempotency_key' => $idempotencyKey,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'digital_media_id' => $media->id,
                'creator_profile_id' => $media->creator_profile_id,
                'title_snapshot' => $media->title,
                'unit_price_minor' => $gross,
                'currency' => $currency,
                'commission_bps' => $commissionBps,
                'commission_minor' => $split['commission_minor'],
                'creator_net_minor' => $split['creator_net_minor'],
            ]);

            PaymentTransaction::create([
                'provider' => 'paypal',
                'order_reference' => $reference,
                'gross_minor' => $gross,
                'currency' => $currency,
                'status' => 'pending',
                'idempotency_key' => $paymentKey,
            ]);

            return $order;
        });

        try {
            $paypalOrder = $paypal->createOrder($gross, $currency, $reference, $idempotencyKey);
            $paypalOrderId = $paypalOrder['id'] ?? null;
            $approvalUrl = collect($paypalOrder['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

            if (! is_string($paypalOrderId) || ! is_string($approvalUrl)) {
                throw new \RuntimeException('PayPal did not return a valid approval link.');
            }

            $order->update([
                'status' => 'awaiting_approval',
                'paypal_order_id' => $paypalOrderId,
                'approval_url' => $approvalUrl,
            ]);
            $order->paymentTransaction()->update(['provider_order_id' => $paypalOrderId]);

            return $this->checkoutResponse($order->fresh(), 201);
        } catch (Throwable $exception) {
            $order->update(['status' => 'failed']);
            $order->paymentTransaction()->update(['status' => 'failed']);
            report($exception);

            return response()->json(['message' => 'PayPal checkout could not be initialized. Please retry with a new idempotency key.'], 502);
        }
    }

    public function capture(Request $request, string $reference, PayPalClient $paypal): JsonResponse
    {
        $order = Order::where('order_reference', $reference)
            ->where('buyer_id', $request->user()->id)
            ->with(['item', 'paymentTransaction'])
            ->firstOrFail();

        if ($order->status === 'captured') {
            return response()->json(['order_reference' => $order->order_reference, 'status' => 'captured'], 200);
        }

        abort_unless($order->status === 'awaiting_approval' && $order->paypal_order_id, 409, 'This order is not ready for capture.');

        try {
            $captureResponse = $paypal->captureOrder(
                $order->paypal_order_id,
                hash('sha256', $order->idempotency_key.'|capture')
            );
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'PayPal capture could not be confirmed. The order remains pending and can be retried.'], 502);
        }

        $capture = data_get($captureResponse, 'purchase_units.0.payments.captures.0');
        if (data_get($captureResponse, 'status') !== 'COMPLETED' || data_get($capture, 'status') !== 'COMPLETED') {
            return response()->json(['order_reference' => $order->order_reference, 'status' => 'pending_confirmation'], 202);
        }

        $captureId = data_get($capture, 'id');
        $amount = data_get($capture, 'amount.value');
        $currency = data_get($capture, 'amount.currency_code');

        if (! is_string($captureId) || ! is_string($amount) || strtoupper((string) $currency) !== $order->currency) {
            return response()->json(['message' => 'PayPal capture response does not match the expected order.'], 409);
        }

        if ($paypal->parseMinor($amount) !== $order->gross_minor) {
            return response()->json(['message' => 'PayPal captured amount does not match the order total.'], 409);
        }

        DB::transaction(function () use ($order, $captureId): void {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status === 'captured') {
                return;
            }

            $item = $lockedOrder->item()->firstOrFail();
            $payment = PaymentTransaction::where('order_reference', $lockedOrder->order_reference)->lockForUpdate()->firstOrFail();
            $now = now();

            $payment->update([
                'provider_capture_id' => $captureId,
                'status' => 'captured',
                'captured_at' => $now,
            ]);

            MediaAccessGrant::firstOrCreate(
                ['buyer_id' => $lockedOrder->buyer_id, 'digital_media_id' => $item->digital_media_id],
                [
                    'payment_transaction_id' => $payment->id,
                    'grant_type' => 'paid',
                    'granted_at' => $now,
                ]
            );

            if ($item->creator_net_minor > 0) {
                $account = CreatorLedgerAccount::firstOrCreate([
                    'creator_profile_id' => $item->creator_profile_id,
                    'currency' => $item->currency,
                    'account_type' => 'pending',
                ]);

                $entry = CreatorLedgerEntry::firstOrCreate(
                    ['idempotency_key' => 'sale-'.$payment->id.'-creator-net'],
                    [
                        'ledger_account_id' => $account->id,
                        'payment_transaction_id' => $payment->id,
                        'entry_type' => 'sale_credit_pending',
                        'amount_minor' => $item->creator_net_minor,
                        'currency' => $item->currency,
                        'reference_type' => 'order',
                        'reference_id' => $lockedOrder->order_reference,
                        'effective_at' => $now,
                    ]
                );

                FundHold::firstOrCreate(
                    ['ledger_entry_id' => $entry->id],
                    [
                        'amount_minor' => $item->creator_net_minor,
                        'currency' => $item->currency,
                        'release_at' => $now->copy()->addDays((int) config('marketplace.hold_days', 14)),
                        'status' => 'pending',
                        'reason' => 'refund_dispute_window',
                    ]
                );
            }

            $lockedOrder->update(['status' => 'captured']);
        });

        return response()->json(['order_reference' => $order->order_reference, 'status' => 'captured'], 200);
    }

    private function checkoutResponse(Order $order, int $status): JsonResponse
    {
        return response()->json([
            'order_reference' => $order->order_reference,
            'status' => $order->status,
            'paypal_order_id' => $order->paypal_order_id,
            'approval_url' => $order->approval_url,
            'gross_minor' => $order->gross_minor,
            'currency' => $order->currency,
        ], $status);
    }
}
