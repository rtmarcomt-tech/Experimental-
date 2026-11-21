<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CreatorLedgerEntry extends Model
{
    protected $fillable = [
        'ledger_account_id', 'payment_transaction_id', 'entry_type', 'amount_minor',
        'currency', 'reference_type', 'reference_id', 'idempotency_key', 'effective_at',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'effective_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only; create a reversal entry instead.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CreatorLedgerAccount::class, 'ledger_account_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
