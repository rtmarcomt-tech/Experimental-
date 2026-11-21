<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatorApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->creatorProfile()->with('verificationCases')->first();

        return response()->json(['creator_profile' => $profile]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_name' => ['required', 'string', 'min:2', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'accept_creator_terms' => ['accepted'],
        ]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $user = $request->user();
            $profile = CreatorProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if ($profile && $profile->status !== 'rejected') {
                throw ValidationException::withMessages([
                    'creator_profile' => ['An application already exists or is under review.'],
                ]);
            }

            $baseSlug = Str::slug($data['public_name']);
            $slug = $baseSlug;
            $suffix = 2;

            while (CreatorProfile::where('slug', $slug)->when($profile, fn ($q) => $q->where('id', '!=', $profile->id))->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            if ($profile) {
                $profile->update([
                    'public_name' => $data['public_name'],
                    'slug' => $slug,
                    'bio' => $data['bio'] ?? null,
                    'country_code' => strtoupper($data['country_code']),
                    'status' => 'pending',
                    'approved_at' => null,
                    'reviewed_by' => null,
                ]);
            } else {
                $profile = CreatorProfile::create([
                    'user_id' => $user->id,
                    'public_name' => $data['public_name'],
                    'slug' => $slug,
                    'bio' => $data['bio'] ?? null,
                    'country_code' => strtoupper($data['country_code']),
                    'status' => 'pending',
                ]);
            }

            $case = $profile->verificationCases()->create([
                'status' => 'submitted',
                'submitted_data' => [
                    'public_name' => $data['public_name'],
                    'country_code' => strtoupper($data['country_code']),
                    'creator_terms_accepted' => true,
                ],
                'submitted_at' => now(),
            ]);

            return response()->json([
                'creator_profile' => $profile->fresh(),
                'verification_case' => $case,
            ], 201);
        });
    }
}
