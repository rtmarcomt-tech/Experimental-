<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\MediaAccessGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorContentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_unpublish_own_content_and_it_disappears_from_public_pages(): void
    {
        $creator = User::create([
            'name' => 'Creator',
            'email' => 'lifecycle-creator@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $creator->id,
            'public_name' => 'Creator Public',
            'slug' => 'creator-public-lifecycle',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Published work',
            'slug' => 'published-work-lifecycle',
            'visibility' => 'free',
            'currency' => 'EUR',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($creator)
            ->post(route('creator.media.unpublish', $media->id))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('digital_media', [
            'id' => $media->id,
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->get('/media/'.$media->slug)->assertNotFound();
        $this->get('/')->assertDontSee('Published work');
    }

    public function test_creator_cannot_unpublish_another_creators_content(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'lifecycle-owner@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        $other = User::create([
            'name' => 'Other',
            'email' => 'lifecycle-other@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $owner->id,
            'public_name' => 'Owner',
            'slug' => 'lifecycle-owner',
            'status' => 'approved',
        ]);
        CreatorProfile::create([
            'user_id' => $other->id,
            'public_name' => 'Other',
            'slug' => 'lifecycle-other',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Owners work',
            'slug' => 'owners-work-lifecycle',
            'visibility' => 'free',
            'currency' => 'EUR',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($other)
            ->post(route('creator.media.unpublish', $media->id))
            ->assertNotFound();

        $this->assertDatabaseHas('digital_media', ['id' => $media->id, 'status' => 'published']);
    }

    public function test_suspended_creator_content_is_not_public_or_claimable(): void
    {
        $creator = User::create([
            'name' => 'Suspended creator',
            'email' => 'lifecycle-suspended@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'suspended',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $creator->id,
            'public_name' => 'Suspended public',
            'slug' => 'suspended-public-lifecycle',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Hidden work',
            'slug' => 'hidden-work-lifecycle',
            'visibility' => 'free',
            'currency' => 'EUR',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/criador/'.$profile->slug)->assertNotFound();
        $this->get('/media/'.$media->slug)->assertNotFound();
        $this->post('/media/'.$media->slug.'/claim')->assertNotFound();
        $this->assertSame(0, MediaAccessGrant::where('digital_media_id', $media->id)->count());
    }

    public function test_paid_content_page_does_not_offer_checkout_in_the_mvp(): void
    {
        $creator = User::create([
            'name' => 'Paid creator',
            'email' => 'lifecycle-paid@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $creator->id,
            'public_name' => 'Paid public',
            'slug' => 'paid-public-lifecycle',
            'status' => 'approved',
        ]);
        $media = DigitalMedia::create([
            'creator_profile_id' => $profile->id,
            'type' => 'photo',
            'title' => 'Paid work',
            'slug' => 'paid-work-lifecycle',
            'visibility' => 'paid',
            'price_minor' => 1500,
            'currency' => 'EUR',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/media/'.$media->slug)
            ->assertOk()
            ->assertSee('Compra individual disponível numa fase posterior.')
            ->assertDontSee('Comprar este conteúdo')
            ->assertDontSee('Pagamento através do PayPal');
    }
}
