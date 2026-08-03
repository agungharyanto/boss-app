<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_returned_when_user_has_no_saved_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/settings/theme');

        $response->assertOk();
        $response->assertJsonPath('data.primary_color', '#2563eb');
        $response->assertJsonPath('data.text_color', '#1f2937');
    }

    public function test_updating_persists_the_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/theme', [
            'primary_color' => '#16a34a',
            'text_color' => '#111827',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.primary_color', '#16a34a');
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme_primary_color' => '#16a34a',
            'theme_text_color' => '#111827',
        ]);
    }

    public function test_an_invalid_color_value_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/theme', [
            'primary_color' => 'not-a-color',
            'text_color' => '#111827',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['primary_color']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/settings/theme')->assertUnauthorized();
    }
}
