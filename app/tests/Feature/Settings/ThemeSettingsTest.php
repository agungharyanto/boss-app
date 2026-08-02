<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\ThemeSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_shown_when_user_has_no_saved_preference(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ThemeSettings::class)
            ->assertSet('primaryColor', '#2563eb')
            ->assertSet('textColor', '#1f2937');
    }

    public function test_saving_persists_the_preference(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ThemeSettings::class)
            ->set('primaryColor', '#16a34a')
            ->set('textColor', '#111827')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme_primary_color' => '#16a34a',
            'theme_text_color' => '#111827',
        ]);
    }

    public function test_an_invalid_color_value_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ThemeSettings::class)
            ->set('primaryColor', 'not-a-color')
            ->call('save')
            ->assertHasErrors(['primaryColor']);

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_mount_loads_the_existing_saved_preference(): void
    {
        $user = User::factory()->create();
        $user->preference()->create([
            'theme_primary_color' => '#dc2626',
            'theme_text_color' => '#0f172a',
        ]);

        Livewire::actingAs($user)
            ->test(ThemeSettings::class)
            ->assertSet('primaryColor', '#dc2626')
            ->assertSet('textColor', '#0f172a');
    }
}
