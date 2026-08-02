<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LanguageSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_locale_sets_the_session_and_applies_immediately(): void
    {
        $this->get('/lang/en')->assertRedirect();

        $this->assertSame('en', session('locale'));

        $this->get('/');
        $this->assertSame('en', App::getLocale());
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $this->get('/lang/fr')->assertNotFound();
    }

    public function test_switching_locale_while_logged_in_persists_to_the_users_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/lang/en')->assertRedirect();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_a_returning_logged_in_user_gets_their_saved_locale_without_switching_again(): void
    {
        $user = User::factory()->create();
        $user->preference()->create(['locale' => 'en']);

        // Fresh request, no session locale set yet — must fall back to the
        // user's saved preference.
        $this->actingAs($user)->get('/customers');

        $this->assertSame('en', App::getLocale());
    }
}
