<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'digital_media_id', 'creator_profile_id', 'title_snapshot',
        'unit_price_minor', 'currency', 'commission_bps', 'commission_minor', 'creator_net_minor',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'commission_bps' => 'integer',
            'commission_minor' => 'integer',
            'creator_net_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function digitalMedia(): BelongsTo
    {
        return $this->belongsTo(DigitalMedia::class);
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class);
    }
}
