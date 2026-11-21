<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorVerificationCase;
use App\Models\CreatorVerificationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCreatorVerificationController extends Controller
{
    public function index(): JsonResponse
    {
        $cases = CreatorVerificationCase::with([
            'creatorProfile:id,user_id,public_name,slug,status',
            'documents:id,creator_verification_case_id,document_type,mime_type,size_bytes,status,created_at',
        ])->where('status', 'submitted')->orderBy('submitted_at')->paginate(25);

        return response()->json($cases);
    }

    public function downloadDocument(CreatorVerificationDocument $document): StreamedResponse
    {
        $extension = match ($document->mime_type) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return Storage::disk($document->disk)->download(
            $document->object_key,
            'creator-verification-'.$document->id.'.'.$extension
        );
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

            if ($approved && ! $lockedCase->documents()->where('document_type', 'identity')->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages([
                    'documents' => ['An identity document must be submitted before approval.'],
                ]);
            }

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
