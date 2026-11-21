<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreatorMediaPublicationController extends Controller
{
    public function declareRights(Request $request, int $media): JsonResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403, 'An approved creator account is required.');

        $item = $profile->media()->where('status', 'draft')->findOrFail($media);
        $data = $request->validate([
            'rights_basis' => ['required', 'in:owner,licensed,authorized'],
            'accept_rights_declaration' => ['accepted'],
        ]);

        $declaration = $item->rightsDeclarations()->create([
            'creator_profile_id' => $profile->id,
            'accepted_by' => $request->user()->id,
            'rights_basis' => $data['rights_basis'],
            'terms_version' => 'v1',
            'accepted_at' => now(),
        ]);

        return response()->json(['rights_declaration' => $declaration], 201);
    }

    public function uploadPreview(Request $request, int $media): JsonResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403, 'An approved creator account is required.');

        $item = $profile->media()->where('status', 'draft')->findOrFail($media);
        $request->validate([
            'preview' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('preview');
        $path = $file->store('creator-media/'.$profile->id.'/previews', 'private');

        try {
            $asset = $item->assets()->create([
                'purpose' => 'preview',
                'disk' => 'private',
                'object_key' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'width' => null,
                'height' => null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return response()->json([
            'preview' => ['id' => $asset->id, 'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes],
        ], 201);
    }

    public function publish(Request $request, int $media): JsonResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403, 'An approved creator account is required.');

        $item = $profile->media()->where('status', 'draft')->findOrFail($media);

        $hasOriginal = $item->assets()->where('purpose', 'original')->exists();
        $hasPreview = $item->assets()->where('purpose', 'preview')->exists();
        $hasRightsDeclaration = $item->rightsDeclarations()->exists();

        if (! $hasOriginal || ! $hasPreview || ! $hasRightsDeclaration) {
            throw ValidationException::withMessages([
                'publication' => ['An original file, a preview image and an accepted rights declaration are required before publication.'],
            ]);
        }

        $item->update(['status' => 'published', 'published_at' => now()]);

        return response()->json(['media' => $item->fresh()], 200);
    }

    public function publicIndex(): JsonResponse
    {
        $items = DigitalMedia::query()
            ->where('status', 'published')
            ->whereHas('creatorProfile', fn ($query) => $query->where('status', 'approved')->whereHas('user', fn ($user) => $user->where('account_status', 'active')))
            ->with('creatorProfile:id,public_name,slug')
            ->select(['id', 'creator_profile_id', 'type', 'title', 'slug', 'description', 'visibility', 'price_minor', 'currency', 'published_at'])
            ->latest('published_at')
            ->paginate(24);

        $items->getCollection()->transform(function (DigitalMedia $item): array {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'slug' => $item->slug,
                'description' => $item->description,
                'visibility' => $item->visibility,
                'price_minor' => $item->price_minor,
                'currency' => $item->currency,
                'creator' => ['public_name' => $item->creatorProfile->public_name, 'slug' => $item->creatorProfile->slug],
                'preview_url' => url('/api/media/'.$item->slug.'/preview'),
            ];
        });

        return response()->json($items);
    }

    public function publicPreview(string $slug)
    {
        $item = DigitalMedia::where('slug', $slug)->where('status', 'published')->whereHas('creatorProfile', fn ($query) => $query->where('status', 'approved')->whereHas('user', fn ($user) => $user->where('account_status', 'active')))->firstOrFail();
        $asset = $item->assets()->where('purpose', 'preview')->latest('id')->firstOrFail();

        return Storage::disk($asset->disk)->response($asset->object_key, null, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
