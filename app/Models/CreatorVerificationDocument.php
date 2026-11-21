<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorVerificationDocument extends Model
{
    protected $fillable = [
        'creator_verification_case_id', 'document_type', 'disk', 'object_key',
        'mime_type', 'size_bytes', 'sha256', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function verificationCase(): BelongsTo
    {
        return $this->belongsTo(CreatorVerificationCase::class, 'creator_verification_case_id');
    }
}
