<?php

namespace Tests\Feature;

use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

/**
 * Avatars go to Cloudinary, so the SDK is mocked here: the suite must never
 * reach the real API, and it must pass without valid credentials in .env.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $cloud = 'qmallj8e';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Fake the Cloudinary SDK. $counter makes every upload return a distinct
     * public_id, so a replace is observable. $destroyed collects the public ids
     * handed to destroy(), which is how a cleanup is asserted.
     *
     * @param  list<string>  $destroyed
     */
    private function fakeCloudinary(array &$destroyed = []): void
    {
        $counter = 0;

        $uploadApi = Mockery::mock();
        $uploadApi->shouldReceive('upload')->andReturnUsing(function () use (&$counter) {
            $publicId = 'avatars/photo-'.(++$counter);

            return (object) [
                'secure_url' => "https://res.cloudinary.com/{$this->cloud}/image/upload/{$publicId}.jpg",
                'public_id' => $publicId,
            ];
        });
        $uploadApi->shouldReceive('destroy')->andReturnUsing(function (string $publicId) use (&$destroyed) {
            $destroyed[] = $publicId;

            return (object) ['result' => 'ok'];
        });

        $cloudinary = Mockery::mock();
        $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

        Cloudinary::swap($cloudinary);
    }

    private function signIn(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/user/profile', ['name' => 'Hacker'])
            ->assertUnauthorized();
    }

    public function test_it_returns_the_profile_fields_on_the_session_endpoint(): void
    {
        $user = $this->signIn(['name' => 'Chandouern Loung', 'phone' => '+855 12 345 678']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Chandouern Loung')
            ->assertJsonPath('user.phone', '+855 12 345 678')
            ->assertJsonPath('user.initials', 'CL')
            ->assertJsonPath('user.avatar_url', null)
            // A real count from the relation, never a hardcoded number.
            ->assertJsonPath('user.orders_count', 0);
    }

    public function test_it_updates_name_and_phone(): void
    {
        $user = $this->signIn();

        $this->postJson('/api/user/profile', [
            'name' => 'New Name',
            'phone' => '+855 99 888 777',
        ])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.phone', '+855 99 888 777');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'phone' => '+855 99 888 777',
        ]);
    }

    public function test_it_rejects_a_blank_name(): void
    {
        $this->signIn();

        $this->postJson('/api/user/profile', ['name' => ''])->assertStatus(422);
    }

    public function test_it_rejects_a_phone_longer_than_thirty_characters(): void
    {
        $this->signIn();

        $this->postJson('/api/user/profile', ['phone' => str_repeat('9', 31)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_it_accepts_a_json_patch_for_text_only_edits(): void
    {
        $this->signIn();

        $this->patchJson('/api/user/profile', ['name' => 'Patched Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Patched Name');
    }

    public function test_it_uploads_an_avatar_and_returns_the_cloudinary_url(): void
    {
        $this->fakeCloudinary();
        $user = $this->signIn();

        $response = $this->post('/api/user/profile', [
            'name' => 'With Avatar',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $avatar = $response->json('user.avatar');

        $this->assertSame(
            "https://res.cloudinary.com/{$this->cloud}/image/upload/avatars/photo-1.jpg",
            $avatar,
        );

        // Absolute URLs must survive untouched: prefixing them with /storage/
        // is what used to break avatars once they moved to Cloudinary.
        $this->assertSame($avatar, $response->json('user.avatar_url'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => $avatar]);
    }

    public function test_it_keeps_a_legacy_local_path_prefixed_with_storage(): void
    {
        $user = $this->signIn(['avatar' => 'avatars/old.jpg']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', '/storage/avatars/old.jpg');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'avatars/old.jpg']);
    }

    public function test_it_deletes_the_previous_avatar_when_replaced(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $user = $this->signIn();

        $first = $this->post('/api/user/profile', [
            'name' => 'User',
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->json('user.avatar');

        $second = $this->post('/api/user/profile', [
            'name' => 'User',
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->json('user.avatar');

        $this->assertNotSame($first, $second);

        // The replaced asset is destroyed through the API, not the local disk,
        // so it does not linger in the cloud forever.
        $this->assertContains('avatars/photo-1', $destroyed);
        $this->assertNotContains('avatars/photo-2', $destroyed);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => $second]);
    }

    public function test_it_clears_the_avatar_on_request(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $user = $this->signIn();

        $this->post('/api/user/profile', [
            'name' => 'User',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->json('user.avatar');

        $this->post('/api/user/profile', [
            'name' => 'User',
            'remove_avatar' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('user.avatar', null)
            ->assertJsonPath('user.avatar_url', null);

        $this->assertContains('avatars/photo-1', $destroyed);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => null]);
    }

    public function test_it_keeps_the_avatar_when_saving_text_fields_only(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $this->signIn();

        $avatar = $this->post('/api/user/profile', [
            'name' => 'User',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->json('user.avatar');

        $this->post('/api/user/profile', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('user.avatar', $avatar)
            ->assertJsonPath('user.name', 'Renamed');

        // A text-only edit must not destroy the image it is keeping.
        $this->assertSame([], $destroyed);
    }
}
