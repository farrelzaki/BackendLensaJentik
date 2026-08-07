<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_profile_without_avatar(): void
    {
        $user = User::create([
            'nama' => 'Original Name',
            'email' => 'original@example.com',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/update-profile', [
                'nama' => 'Updated Name',
                'phone' => '0987654321',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.nama', 'Updated Name');
        $response->assertJsonPath('data.phone', '0987654321');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'nama' => 'Updated Name',
            'phone' => '0987654321',
        ]);
    }

    public function test_user_can_update_profile_avatar_using_spoofed_patch(): void
    {
        Storage::fake('public');

        $user = User::create([
            'nama' => 'Original Name',
            'email' => 'original_avatar@example.com',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
        ]);

        $avatarFile = UploadedFile::fake()->image('my_avatar.png');

        // We make a POST request with _method: PATCH inside the multipart data
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/update-profile', [
                '_method' => 'PATCH',
                'nama' => 'Updated Name Spof',
                'phone' => '09876543210',
                'avatar' => $avatarFile,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.nama', 'Updated Name Spof');
        $response->assertJsonPath('data.phone', '09876543210');

        $user->refresh();
        $this->assertNotNull($user->avatar);
        $this->assertStringContainsString('uploads/avatars', $user->avatar);

        // Verify the file exists in the public directory structure
        $fileName = basename($user->avatar);
        $this->assertFileExists(public_path('uploads/avatars/' . $fileName));

        // Clean up the created test file
        if (file_exists(public_path('uploads/avatars/' . $fileName))) {
            unlink(public_path('uploads/avatars/' . $fileName));
        }
    }
}
