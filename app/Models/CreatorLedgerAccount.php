<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorLedgerAccount extends Model
{
    protected $fillable = ['creator_profile_id', 'currency', 'account_type'];

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CreatorLedgerEntry::class, 'ledger_account_id');
    }
}
