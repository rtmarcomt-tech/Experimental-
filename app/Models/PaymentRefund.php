<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'payment_transaction_id', 'provider_refund_id', 'idempotency_key',
        'gross_minor', 'creator_reversal_minor', 'platform_reversal_minor',
        'currency', 'status', 'source', 'note', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'creator_reversal_minor' => 'integer',
            'platform_reversal_minor' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
