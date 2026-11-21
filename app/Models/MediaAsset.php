<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MediaAsset extends Model { protected $fillable=['digital_media_id','purpose','disk','object_key','mime_type','size_bytes','sha256','width','height','duration_seconds']; public function digitalMedia(): BelongsTo { return $this->belongsTo(DigitalMedia::class); } }