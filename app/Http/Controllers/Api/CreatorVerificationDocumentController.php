<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreatorVerificationDocumentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'in:identity'],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $profile = $request->user()->creatorProfile;
        if (! $profile || $profile->status !== 'pending') {
            throw ValidationException::withMessages([
                'creator_profile' => ['A pending creator application is required to submit verification documents.'],
            ]);
        }

        $case = $profile->verificationCases()->where('status', 'submitted')->latest('id')->first();
        if (! $case) {
            throw ValidationException::withMessages([
                'verification_case' => ['There is no open verification case for this creator.'],
            ]);
        }

        $file = $request->file('document');
        $disk = 'private';
        $path = $file->store('creator-verification/'.$case->id, $disk);

        $document = $case->documents()->create([
            'document_type' => $data['document_type'],
            'disk' => $disk,
            'object_key' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'status' => 'submitted',
        ]);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'status' => $document->status,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
            ],
        ], 201);
    }
}
