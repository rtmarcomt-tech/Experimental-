<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_requires_original_preview_and_rights_declaration(): void
    {
        Storage::fake('private');
        $user = User::create(['name' => 'Publisher', 'email' => 'publisher@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Publisher', 'slug' => 'publisher', 'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id, 'type' => 'photo', 'title' => 'Sample Photo',
            'slug' => 'sample-photo-publish-test', 'visibility' => 'free', 'status' => 'draft',
        ]);
        MediaAsset::create([
            'digital_media_id' => $media->id, 'purpose' => 'original', 'disk' => 'private',
            'object_key' => 'originals/sample-photo.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 100, 'sha256' => str_repeat('a', 64),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/creator/media/'.$media->id.'/publish', [])->assertUnprocessable();

        $this->postJson('/api/creator/media/'.$media->id.'/rights', [
            'rights_basis' => 'owner', 'accept_rights_declaration' => true,
        ])->assertCreated();

        $this->postJson('/api/creator/media/'.$media->id.'/publish', [])->assertUnprocessable();

        $this->post('/api/creator/media/'.$media->id.'/preview', [
            'preview' => UploadedFile::fake()->image('preview.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/creator/media/'.$media->id.'/publish', [])
            ->assertOk()->assertJsonPath('media.status', 'published');

        $this->getJson('/api/media')->assertOk()
            ->assertJsonFragment(['slug' => 'sample-photo-publish-test']);

        $this->get('/api/media/sample-photo-publish-test/preview')->assertOk();
    }

    public function test_unapproved_creator_cannot_publish_media_even_with_rights_and_preview(): void
    {
        $user = User::create(['name' => 'Pending', 'email' => 'pending-publisher@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Pending', 'slug' => 'pending-publisher', 'status' => 'pending',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id, 'type' => 'photo', 'title' => 'Draft',
            'slug' => 'pending-draft', 'visibility' => 'free', 'status' => 'draft',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/creator/media/'.$media->id.'/publish', [])->assertForbidden();
    }
}
