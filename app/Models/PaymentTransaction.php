<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'provider', 'provider_order_id', 'provider_capture_id', 'order_reference',
        'gross_minor', 'currency', 'status', 'idempotency_key', 'captured_at',
    ];

    protected function casts(): array
    {
        return ['gross_minor' => 'integer', 'captured_at' => 'datetime'];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreatorLedgerEntry::class);
    }
}
