<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CreatorVerificationCase extends Model { protected $fillable=['creator_profile_id','status','submitted_data','decision_reason','reviewed_by','submitted_at','reviewed_at']; protected function casts(): array { return ['submitted_data'=>'array','submitted_at'=>'datetime','reviewed_at'=>'datetime']; } public function creatorProfile(): BelongsTo { return $this->belongsTo(CreatorProfile::class); } }