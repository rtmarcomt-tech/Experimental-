<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\MediaAccessGrant;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_published_media_can_be_claimed_and_downloaded(): void
    {
        Storage::fake('private');
        $buyer = User::create(['name' => 'Free Buyer', 'email' => 'free-buyer@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPublishedMedia('free', 'free-library-item');
        $path = 'creator-media/originals/free-library-item.jpg';
        Storage::disk('private')->put($path, 'image-bytes');
        MediaAsset::create([
            'digital_media_id' => $media->id,
            'purpose' => 'original',
            'disk' => 'private',
            'object_key' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'image-bytes'),
        ]);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/media/'.$media->slug.'/claim', [])->assertCreated()
            ->assertJsonPath('grant.grant_type', 'free');
        $this->postJson('/api/media/'.$media->slug.'/claim', [])->assertCreated();

        self::assertSame(1, MediaAccessGrant::count());
        $this->getJson('/api/library')->assertOk()->assertJsonFragment(['slug' => $media->slug]);

        $download = $this->get('/api/library/'.$media->id.'/download');
        $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('media_download_events', [
            'media_access_grant_id' => MediaAccessGrant::firstOrFail()->id,
            'media_asset_id' => MediaAsset::firstOrFail()->id,
            'outcome' => 'issued',
        ]);
    }

    public function test_paid_original_cannot_be_downloaded_without_active_entitlement(): void
    {
        Storage::fake('private');
        $buyer = User::create(['name' => 'No Access', 'email' => 'no-access@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPublishedMedia('paid', 'paid-library-item');
        Sanctum::actingAs($buyer);

        $this->get('/api/library/'.$media->id.'/download')->assertNotFound();
        $this->assertDatabaseCount('media_download_events', 0);
    }

    public function test_another_buyer_cannot_use_someone_elses_entitlement(): void
    {
        Storage::fake('private');
        $owner = User::create(['name' => 'Grant Owner', 'email' => 'grant-owner@example.test', 'password' => 'password-long-enough']);
        $other = User::create(['name' => 'Different Buyer', 'email' => 'different-buyer@example.test', 'password' => 'password-long-enough']);
        $media = $this->createPublishedMedia('paid', 'other-buyers-item');
        $grant = MediaAccessGrant::create([
            'buyer_id' => $owner->id,
            'digital_media_id' => $media->id,
            'grant_type' => 'paid',
            'granted_at' => now(),
        ]);
        Sanctum::actingAs($other);

        $this->get('/api/library/'.$media->id.'/download')->assertNotFound();
        $this->assertDatabaseHas('media_access_grants', ['id' => $grant->id, 'buyer_id' => $owner->id]);
    }

    private function createPublishedMedia(string $visibility, string $slug): DigitalMedia
    {
        $seller = User::create(['name' => 'Library Seller', 'email' => $slug.'@example.test', 'password' => 'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id' => $seller->id,
            'public_name' => 'Library Seller',
            'slug' => 'seller-'.$slug,
            'status' => 'approved',
        ]);

        return DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Library Item',
            'slug' => $slug,
            'visibility' => $visibility,
            'price_minor' => $visibility === 'paid' ? 1500 : null,
            'currency' => 'EUR',
            'status' => 'published',
        ]);
    }
}
