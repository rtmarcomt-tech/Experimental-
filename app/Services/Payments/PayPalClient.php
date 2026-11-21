<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayPalClient
{
    private function baseUrl(): string
    {
        return config('paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(): string
    {
        $clientId = (string) config('paypal.client_id');
        $clientSecret = (string) config('paypal.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('PayPal API credentials are not configured.');
        }

        $cacheKey = 'paypal_access_token_'.hash('sha256', $clientId);

        return Cache::remember($cacheKey, now()->addMinutes(8), function () use ($clientId, $clientSecret): string {
            $response = Http::baseUrl($this->baseUrl())
                ->withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->acceptJson()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->throw();

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('PayPal did not return an access token.');
            }

            return $token;
        });
    }

    public function createOrder(int $grossMinor, string $currency, string $reference, string $idempotencyKey): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->acceptJson()
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $reference,
                    'invoice_id' => $reference,
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $this->formatMinor($grossMinor),
                    ],
                ]],
                'application_context' => [
                    'return_url' => config('paypal.return_url'),
                    'cancel_url' => config('paypal.cancel_url'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                ],
            ])
            ->throw();

        return $response->json();
    }

    public function captureOrder(string $paypalOrderId, string $idempotencyKey): array
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->acceptJson()
            ->post('/v2/checkout/orders/'.$paypalOrderId.'/capture', (object) [])
            ->throw()
            ->json();
    }

    public function verifyWebhook(array $headers, array $event): bool
    {
        $webhookId = (string) config('paypal.webhook_id');
        if ($webhookId === '') {
            throw new RuntimeException('PayPal webhook ID is not configured.');
        }

        $payload = [
            'auth_algo' => $headers['auth_algo'] ?? null,
            'cert_url' => $headers['cert_url'] ?? null,
            'transmission_id' => $headers['transmission_id'] ?? null,
            'transmission_sig' => $headers['transmission_sig'] ?? null,
            'transmission_time' => $headers['transmission_time'] ?? null,
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ];

        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post('/v1/notifications/verify-webhook-signature', $payload)
            ->throw();

        return $response->json('verification_status') === 'SUCCESS';
    }

    public function formatMinor(int $amount): string
    {
        if ($amount < 0) {
            throw new RuntimeException('Payment amount cannot be negative.');
        }

        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    public function parseMinor(string $amount): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('PayPal returned an invalid EUR amount.');
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        return ((int) $matches[1] * 100) + (int) $fraction;
    }
}
