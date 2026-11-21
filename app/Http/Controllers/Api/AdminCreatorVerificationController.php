<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorVerificationCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCreatorVerificationController extends Controller
{
    public function index(): JsonResponse
    {
        $cases = CreatorVerificationCase::with('creatorProfile:id,user_id,public_name,slug,status')
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->paginate(25);

        return response()->json($cases);
    }

    public function decide(Request $request, CreatorVerificationCase $case): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $case, $data): JsonResponse {
            $lockedCase = CreatorVerificationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($lockedCase->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'case' => ['This verification case has already been reviewed.'],
                ]);
            }

            $profile = $lockedCase->creatorProfile()->lockForUpdate()->firstOrFail();
            $approved = $data['decision'] === 'approved';

            $lockedCase->update([
                'status' => $approved ? 'approved' : 'rejected',
                'decision_reason' => $data['reason'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $profile->update([
                'status' => $approved ? 'approved' : 'rejected',
                'approved_at' => $approved ? now() : null,
                'reviewed_by' => $request->user()->id,
            ]);

            return response()->json([
                'verification_case' => $lockedCase->fresh(),
                'creator_profile' => $profile->fresh(),
            ]);
        });
    }
}
