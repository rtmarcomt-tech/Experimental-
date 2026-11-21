<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreatorMediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->creatorProfile;

        if (! $profile) {
            return response()->json(['data' => []]);
        }

        return response()->json(
            $profile->media()->with('assets:id,digital_media_id,purpose,mime_type,size_bytes')
                ->latest()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $profile = $request->user()->creatorProfile;

        abort_unless($profile && $profile->canSell(), 403, 'An approved creator account is required.');

        $data = $request->validate([
            'type' => ['required', 'in:photo,video'],
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['required', 'in:free,paid'],
            'price_minor' => ['nullable', 'required_if:visibility,paid', 'prohibited_if:visibility,free', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        $fileRules = $data['type'] === 'photo'
            ? ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:30720']
            : ['required', 'file', 'mimes:mp4,mov,webm', 'max:512000'];

        $request->validate(['file' => $fileRules]);

        $file = $request->file('file');
        $path = $file->store('creator-media/'.$profile->id.'/originals', 'private');

        try {
            $media = DB::transaction(function () use ($profile, $data, $file, $path): DigitalMedia {
                $media = $profile->media()->create([
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(10)),
                    'description' => $data['description'] ?? null,
                    'visibility' => $data['visibility'],
                    'price_minor' => $data['visibility'] === 'paid' ? (int) $data['price_minor'] : null,
                    'currency' => strtoupper($data['currency'] ?? 'EUR'),
                    'status' => 'draft',
                ]);

                $media->assets()->create([
                    'purpose' => 'original',
                    'disk' => 'private',
                    'object_key' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                ]);

                return $media;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return response()->json([
            'media' => [
                'id' => $media->id,
                'type' => $media->type,
                'title' => $media->title,
                'slug' => $media->slug,
                'visibility' => $media->visibility,
                'price_minor' => $media->price_minor,
                'currency' => $media->currency,
                'status' => $media->status,
                'created_at' => $media->created_at,
            ],
        ], 201);
    }

    public function show(Request $request, int $media): JsonResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile, 404);

        $item = $profile->media()->with('assets:id,digital_media_id,purpose,mime_type,size_bytes')->findOrFail($media);

        return response()->json(['media' => $item]);
    }

    public function update(Request $request, int $media): JsonResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403, 'An approved creator account is required.');

        $item = $profile->media()->where('status', 'draft')->findOrFail($media);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'visibility' => ['sometimes', 'required', 'in:free,paid'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha'],
        ]);

        $visibility = $data['visibility'] ?? $item->visibility;
        $price = array_key_exists('price_minor', $data) ? $data['price_minor'] : $item->price_minor;

        if ($visibility === 'paid' && (! is_numeric($price) || (int) $price < 1)) {
            throw ValidationException::withMessages(['price_minor' => ['A paid item requires a positive price in minor currency units.']]);
        }

        if ($visibility === 'free') {
            $price = null;
        }

        unset($data['price_minor']);
        $item->fill($data);
        $item->visibility = $visibility;
        $item->price_minor = $price;
        $item->save();

        return response()->json(['media' => $item->fresh()]);
    }
}
