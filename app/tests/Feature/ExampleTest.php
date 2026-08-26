<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * v0.9.2 — root `/` no longer serves a static 200 "welcome" page (that
     * scaffold default was replaced — see routes/web.php's own root route
     * and tests/Feature/Auth/RootRoutingTest.php for the real behavior); a
     * guest is redirected to /login instead.
     */
    public function test_the_application_redirects_a_guest_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
