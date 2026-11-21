<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'buyer_id', 'order_reference', 'status', 'currency', 'gross_minor',
        'payment_provider', 'idempotency_key', 'paypal_order_id', 'approval_url',
    ];

    protected function casts(): array
    {
        return ['gross_minor' => 'integer'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function item(): HasOne
    {
        return $this->hasOne(OrderItem::class);
    }

    public function paymentTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class, 'order_reference', 'order_reference');
    }
}
