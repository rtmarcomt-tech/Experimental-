<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAccessGrant extends Model
{
    protected $fillable = [
        'buyer_id', 'digital_media_id', 'payment_transaction_id', 'grant_type',
        'granted_at', 'revoked_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function digitalMedia(): BelongsTo
    {
        return $this->belongsTo(DigitalMedia::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
