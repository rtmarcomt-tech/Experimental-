<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalMedia;
use App\Models\MediaAccessGrant;
use App\Models\MediaDownloadEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $grants = MediaAccessGrant::where('buyer_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->with('digitalMedia.creatorProfile:id,public_name,slug')
            ->latest('granted_at')
            ->paginate(20);

        return response()->json($grants);
    }

    public function claimFree(Request $request, string $slug): JsonResponse
    {
        $media = DigitalMedia::where('slug', $slug)
            ->where('status', 'published')
            ->where('visibility', 'free')
            ->firstOrFail();

        $grant = DB::transaction(function () use ($request, $media): MediaAccessGrant {
            $grant = MediaAccessGrant::where('buyer_id', $request->user()->id)
                ->where('digital_media_id', $media->id)
                ->lockForUpdate()
                ->first();

            if ($grant) {
                if ($grant->revoked_at !== null) {
                    $grant->update([
                        'grant_type' => 'free',
                        'payment_transaction_id' => null,
                        'granted_at' => now(),
                        'revoked_at' => null,
                        'revocation_reason' => null,
                    ]);
                }

                return $grant->fresh();
            }

            return MediaAccessGrant::create([
                'buyer_id' => $request->user()->id,
                'digital_media_id' => $media->id,
                'grant_type' => 'free',
                'granted_at' => now(),
            ]);
        });

        return response()->json(['grant' => $grant], 201);
    }

    public function download(Request $request, int $media): StreamedResponse|JsonResponse
    {
        $grant = MediaAccessGrant::where('buyer_id', $request->user()->id)
            ->where('digital_media_id', $media)
            ->whereNull('revoked_at')
            ->with('digitalMedia')
            ->firstOrFail();

        $item = $grant->digitalMedia;
        abort_unless($item && $item->status === 'published', 404);

        $asset = $item->assets()->where('purpose', 'original')->latest('id')->firstOrFail();
        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->object_key)) {
            return response()->json(['message' => 'The original file is temporarily unavailable.'], 503);
        }

        $key = (string) config('app.key');
        MediaDownloadEvent::create([
            'media_access_grant_id' => $grant->id,
            'media_asset_id' => $asset->id,
            'user_id' => $request->user()->id,
            'outcome' => 'issued',
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), $key) : null,
            'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', $request->userAgent(), $key) : null,
            'issued_at' => now(),
        ]);

        $extension = match ($asset->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => 'bin',
        };

        return $disk->download(
            $asset->object_key,
            Str::slug($item->title).'.'.$extension,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
