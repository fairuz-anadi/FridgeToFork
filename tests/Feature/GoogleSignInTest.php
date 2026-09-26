<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Continue with Google": the browser sends Google's ID token, the API checks
 * it with Google (faked here) and logs the cook in.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'test-client.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    private function fakeGoogle(array $claims): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($claims + [
                'aud' => self::CLIENT_ID,
                'sub' => 'google-123',
                'email' => 'cook@example.com',
                'name' => 'Google Cook',
            ]),
        ]);
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $this->postJson('/api/auth/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_token');
    }

    public function test_a_new_google_user_gets_an_account_and_a_token(): void
    {
        $this->fakeGoogle([]);

        $this->postJson('/api/auth/google', ['id_token' => 'signed-token'])
            ->assertOk()
            ->assertJsonPath('user.email', 'cook@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', ['email' => 'cook@example.com', 'google_id' => 'google-123']);
    }

    public function test_an_existing_account_is_linked_without_losing_its_password(): void
    {
        $user = User::factory()->create([
            'email' => 'cook@example.com',
            'username' => 'cook',
            'password' => Hash::make('secret-pass'),
        ]);
        $this->fakeGoogle([]);

        $this->postJson('/api/auth/google', ['id_token' => 'signed-token'])->assertOk();

        $user->refresh();
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('cook', $user->username);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_a_token_for_another_app_is_rejected(): void
    {
        $this->fakeGoogle(['aud' => 'someone-else.apps.googleusercontent.com']);

        $this->postJson('/api/auth/google', ['id_token' => 'signed-token'])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'cook@example.com']);
    }
}
