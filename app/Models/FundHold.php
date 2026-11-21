<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundHold extends Model
{
    protected $fillable = [
        'ledger_entry_id', 'amount_minor', 'currency', 'release_at',
        'status', 'reason', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreatorLedgerEntry::class, 'ledger_entry_id');
    }
}
