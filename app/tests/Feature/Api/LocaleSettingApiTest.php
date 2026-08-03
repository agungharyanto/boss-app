<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleSettingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_returned_when_user_has_no_saved_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/settings/locale');

        $response->assertOk();
        $response->assertJsonPath('data.locale', config('app.locale'));
        $response->assertJsonPath('data.supported', ['id', 'en']);
    }

    public function test_updating_persists_the_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/locale', ['locale' => 'en']);

        $response->assertOk();
        $response->assertJsonPath('data.locale', 'en');
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/locale', ['locale' => 'fr']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['locale']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/settings/locale')->assertUnauthorized();
    }
}
