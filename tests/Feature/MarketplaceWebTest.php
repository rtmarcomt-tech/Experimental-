<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\DigitalMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_storefront_renders_published_media_and_creator_name(): void
    {
        $seller = User::create(['name'=>'Seller','email'=>'web-seller@example.test','password'=>'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id'=>$seller->id,'public_name'=>'Studio North','slug'=>'studio-north','status'=>'approved',
        ]);
        DigitalMedia::create([
            'creator_profile_id'=>$profile->id,'type'=>'photo','title'=>'Editorial Study',
            'slug'=>'editorial-study','visibility'=>'free','currency'=>'EUR','status'=>'published',
        ]);

        $this->get('/')->assertOk()->assertSee('Imagem com')->assertSee('Editorial Study')->assertSee('Studio North');
        $this->get('/media/editorial-study')->assertOk()->assertSee('Editorial Study')->assertSee('Studio North');
    }

    public function test_public_creator_profile_only_exposes_public_identity_and_separates_free_and_paid_media(): void
    {
        $seller = User::create(['name'=>'Private Legal Name','email'=>'profile-owner@example.test','password'=>'password-long-enough']);
        $profile = CreatorProfile::create([
            'user_id'=>$seller->id,'public_name'=>'Artistic Name','slug'=>'artistic-name','presentation'=>'Independent image maker',
            'bio'=>'A short public biography.','status'=>'approved',
        ]);
        DigitalMedia::create([
            'creator_profile_id'=>$profile->id,'type'=>'photo','title'=>'Free Work','slug'=>'free-work',
            'visibility'=>'free','currency'=>'EUR','status'=>'published',
        ]);
        DigitalMedia::create([
            'creator_profile_id'=>$profile->id,'type'=>'video','title'=>'Paid Work','slug'=>'paid-work',
            'visibility'=>'paid','price_minor'=>1200,'currency'=>'EUR','status'=>'published',
        ]);

        $this->get('/criador/artistic-name')->assertOk()
            ->assertSee('Artistic Name')->assertSee('Independent image maker')
            ->assertSee('A short public biography.')
            ->assertSee('Free Work')->assertSee('Paid Work')
            ->assertDontSee('Private Legal Name');
    }

    public function test_visitor_can_register_and_reach_personal_dashboard(): void
    {
        $this->post('/registar', [
            'name'=>'New Member',
            'email'=>'new-member@example.test',
            'password'=>'a-strong-password',
            'password_confirmation'=>'a-strong-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->get('/minha-area')->assertOk()->assertSee('New Member')->assertSee('Candidatura de criador');
    }

    public function test_creator_application_requires_and_stores_identity_document(): void
    {
        Storage::fake('private');
        $user = User::create(['name'=>'Applicant','email'=>'applicant@example.test','password'=>'password-long-enough']);
        $this->actingAs($user)->post('/candidatura-criador', [
            'public_name'=>'Applicant Studio',
            'bio'=>'Independent creator.',
            'country_code'=>'PT',
            'identity_document'=>UploadedFile::fake()->create('identity.pdf',120,'application/pdf'),
            'accept_creator_terms'=>'1',
        ])->assertRedirect(route('dashboard'));

        $profile = CreatorProfile::where('user_id',$user->id)->firstOrFail();
        $this->assertSame('pending',$profile->status);
        $case = $profile->verificationCases()->firstOrFail();
        $document = $case->documents()->firstOrFail();
        $this->assertSame('identity',$document->document_type);
        Storage::disk('private')->assertExists($document->object_key);
    }
}
