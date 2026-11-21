<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalWebhookEvent extends Model
{
    protected $fillable = [
        'provider_event_id', 'event_type', 'resource_id', 'payload',
        'processing_status', 'received_at', 'processed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
