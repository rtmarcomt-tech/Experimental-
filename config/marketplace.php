<?php

return [
    'commission_bps' => (int) env('MARKETPLACE_COMMISSION_BPS', 1000),
    'hold_days' => (int) env('MARKETPLACE_HOLD_DAYS', 14),
    'payout_minimum_minor' => (int) env('MARKETPLACE_PAYOUT_MINIMUM_MINOR', 5000),
    'currency' => strtoupper(env('MARKETPLACE_CURRENCY', 'EUR')),
];
