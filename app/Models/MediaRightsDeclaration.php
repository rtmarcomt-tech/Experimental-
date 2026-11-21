<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaRightsDeclaration extends Model
{
    protected $fillable = [
        'digital_media_id', 'creator_profile_id', 'accepted_by',
        'rights_basis', 'terms_version', 'accepted_at',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
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
