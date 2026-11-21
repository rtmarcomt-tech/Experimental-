<?php

return [
    'mode' => env('PAYPAL_MODE', 'sandbox'),
    'client_id' => env('PAYPAL_CLIENT_ID'),
    'client_secret' => env('PAYPAL_CLIENT_SECRET'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    'return_url' => env('PAYPAL_RETURN_URL', env('APP_URL').'/checkout/paypal/return'),
    'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL').'/checkout/paypal/cancel'),
    'currency' => env('MARKETPLACE_CURRENCY', 'EUR'),
];
