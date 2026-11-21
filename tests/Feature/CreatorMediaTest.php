<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_creator_can_upload_a_photo_as_a_private_draft(): void
    {
        Storage::fake('private');
        $user = User::create(['name' => 'Approved Creator', 'email' => 'approved@example.test', 'password' => 'password-long-enough']);
        CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Approved Creator', 'slug' => 'approved-creator', 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/creator/media', [
            'type' => 'photo',
            'title' => 'Editorial portrait',
            'visibility' => 'paid',
            'price_minor' => 1250,
            'currency' => 'eur',
            'file' => UploadedFile::fake()->image('portrait.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('media.status', 'draft')
            ->assertJsonPath('media.price_minor', 1250);

        $asset = \App\Models\MediaAsset::firstOrFail();
        Storage::disk('private')->assertExists($asset->object_key);
        $this->assertDatabaseHas('digital_media', [
            'creator_profile_id' => $user->creatorProfile->id,
            'visibility' => 'paid',
            'status' => 'draft',
            'price_minor' => 1250,
        ]);
    }

    public function test_pending_creator_cannot_upload_or_publish_media(): void
    {
        Storage::fake('private');
        $user = User::create(['name' => 'Pending Creator', 'email' => 'pending@example.test', 'password' => 'password-long-enough']);
        CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Pending Creator', 'slug' => 'pending-creator', 'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/creator/media', [
            'type' => 'photo',
            'title' => 'Not allowed',
            'visibility' => 'free',
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_creator_cannot_read_another_creators_draft(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-long-enough']);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password-long-enough']);
        CreatorProfile::create([
            'user_id' => $owner->id, 'public_name' => 'Owner', 'slug' => 'owner-profile', 'status' => 'approved',
        ]);
        CreatorProfile::create([
            'user_id' => $other->id, 'public_name' => 'Other', 'slug' => 'other-profile', 'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $owner->creatorProfile->id,
            'type' => 'photo', 'title' => 'Private draft', 'slug' => 'private-draft-unique',
            'visibility' => 'free', 'status' => 'draft',
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/creator/media/'.$media->id)->assertNotFound();
    }

    public function test_free_media_does_not_accept_a_price(): void
    {
        Storage::fake('private');
        $user = User::create(['name' => 'Free Creator', 'email' => 'free@example.test', 'password' => 'password-long-enough']);
        CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Free Creator', 'slug' => 'free-creator', 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/creator/media', [
            'type' => 'photo',
            'title' => 'Free photo',
            'visibility' => 'free',
            'price_minor' => 100,
            'file' => UploadedFile::fake()->image('free.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
