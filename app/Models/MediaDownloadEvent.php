<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaDownloadEvent extends Model
{
    protected $fillable = [
        'media_access_grant_id', 'media_asset_id', 'user_id', 'outcome',
        'ip_hash', 'user_agent_hash', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(MediaAccessGrant::class, 'media_access_grant_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
