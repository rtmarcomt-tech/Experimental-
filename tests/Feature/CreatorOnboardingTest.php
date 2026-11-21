<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\CreatorVerificationCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_creator_application(): void
    {
        $this->postJson('/api/creator/application', [])->assertUnauthorized();
    }

    public function test_user_can_submit_creator_application_and_starts_pending(): void
    {
        $user = User::create(['name' => 'Creator One', 'email' => 'creator@example.test', 'password' => 'password-long-enough']);
        Sanctum::actingAs($user);

        $this->postJson('/api/creator/application', [
            'public_name' => 'Creator One',
            'bio' => 'Photography and video.',
            'country_code' => 'pt',
            'accept_creator_terms' => true,
        ])->assertCreated()
          ->assertJsonPath('creator_profile.status', 'pending')
          ->assertJsonPath('verification_case.status', 'submitted');

        $this->assertDatabaseHas('creator_profiles', ['user_id' => $user->id, 'status' => 'pending']);
        $this->assertDatabaseHas('creator_verification_cases', ['status' => 'submitted']);
    }

    public function test_user_cannot_submit_duplicate_application_while_pending(): void
    {
        $user = User::create(['name' => 'Creator Two', 'email' => 'creator2@example.test', 'password' => 'password-long-enough']);
        CreatorProfile::create([
            'user_id' => $user->id, 'public_name' => 'Creator Two', 'slug' => 'creator-two', 'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/creator/application', [
            'public_name' => 'Creator Two',
            'country_code' => 'PT',
            'accept_creator_terms' => true,
        ])->assertUnprocessable();
    }

    public function test_only_admin_can_approve_and_identity_document_is_required(): void
    {
        Storage::fake('private');
        $owner = User::create(['name' => 'Applicant', 'email' => 'applicant@example.test', 'password' => 'password-long-enough']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-long-enough']);
        $admin->forceFill(['is_admin' => true])->save();
        $profile = CreatorProfile::create([
            'user_id' => $owner->id, 'public_name' => 'Applicant', 'slug' => 'applicant', 'status' => 'pending',
        ]);
        $case = CreatorVerificationCase::create([
            'creator_profile_id' => $profile->id, 'status' => 'submitted',
            'submitted_data' => ['creator_terms_accepted' => true], 'submitted_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/creator-verification-cases/'.$case->id, ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/creator-verification-cases/'.$case->id, ['decision' => 'approved'])->assertUnprocessable();

        Sanctum::actingAs($owner);
        $upload = $this->postJson('/api/creator/application/documents', [
            'document_type' => 'identity',
            'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('document.status', 'submitted');

        $this->assertDatabaseHas('creator_verification_documents', [
            'creator_verification_case_id' => $case->id,
            'document_type' => 'identity',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/creator-verification-cases/'.$case->id, ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('creator_profile.status', 'approved')
            ->assertJsonPath('verification_case.status', 'approved');

        $this->assertDatabaseHas('creator_profiles', ['id' => $profile->id, 'status' => 'approved', 'reviewed_by' => $admin->id]);
    }

    public function test_rejection_requires_reason_and_profile_stays_unapproved(): void
    {
        $owner = User::create(['name' => 'Applicant', 'email' => 'applicant3@example.test', 'password' => 'password-long-enough']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin3@example.test', 'password' => 'password-long-enough']);
        $admin->forceFill(['is_admin' => true])->save();
        $profile = CreatorProfile::create([
            'user_id' => $owner->id, 'public_name' => 'Applicant', 'slug' => 'applicant-3', 'status' => 'pending',
        ]);
        $case = CreatorVerificationCase::create([
            'creator_profile_id' => $profile->id, 'status' => 'submitted',
            'submitted_data' => [], 'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/creator-verification-cases/'.$case->id, ['decision' => 'rejected'])
            ->assertUnprocessable();
        $this->patchJson('/api/admin/creator-verification-cases/'.$case->id, [
            'decision' => 'rejected', 'reason' => 'Verification documents incomplete.',
        ])->assertOk()->assertJsonPath('creator_profile.status', 'rejected');
    }

    public function test_registration_cannot_assign_admin_privileges(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'long-password-123',
            'is_admin' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['id' => $response->json('user.id'), 'is_admin' => false]);
    }
}
