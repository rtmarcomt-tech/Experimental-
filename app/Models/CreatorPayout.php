<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorPayout extends Model
{
    protected $fillable = [
        'creator_profile_id', 'requested_by', 'amount_minor', 'currency',
        'recipient_email', 'status', 'idempotency_key', 'paypal_batch_id',
        'paypal_item_id', 'paypal_sender_item_id', 'failure_reason', 'requested_at', 'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
