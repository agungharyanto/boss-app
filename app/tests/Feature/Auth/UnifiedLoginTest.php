<?php

namespace Tests\Feature\Auth;

use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * v0.22.x — login terpadu: satu pintu di `/login`, field "Email atau Nomor
 * HP". Jalur staff (email) dan jalur Referrer (nomor HP) dari SATU form.
 */
class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        RateLimiter::clear('login');
    }

    private function staffUser(string $email = 'staff@boss.local', string $password = 'rahasia123'): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make($password)]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function referrerUser(string $phone = '081234500999', string $password = 'rahasia123', bool $active = true): Referrer
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'password' => Hash::make($password)]);

        return Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => $phone,
            'user_id' => $user->id,
            'is_active' => $active,
        ]);
    }

    public function test_staff_logs_in_with_email_and_lands_on_the_dashboard(): void
    {
        $user = $this->staffUser();

        $response = $this->post('/login', ['login' => 'staff@boss.local', 'password' => 'rahasia123']);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect(route('web.dashboard'));
    }

    public function test_referrer_logs_in_with_phone_and_lands_on_the_portal(): void
    {
        $referrer = $this->referrerUser();

        $response = $this->post('/login', ['login' => '081234500999', 'password' => 'rahasia123']);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($referrer->user);
        $this->get('/')->assertRedirect(route('web.referrer-portal.dashboard'));
    }

    /**
     * @dataProvider badCredentials
     */
    public function test_all_failure_modes_produce_the_identical_error_and_no_auth(string $login, string $password): void
    {
        $this->staffUser();
        $this->referrerUser();

        $response = $this->from('/login')->post('/login', ['login' => $login, 'password' => $password]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['login' => __('auth.failed')]);
        $this->assertGuest();
    }

    public static function badCredentials(): array
    {
        // (empty `login` sengaja tidak di sini — itu kena aturan
        // "field required" milik Fortify LoginRequest lebih dulu, pesannya
        // beda tapi tetap tidak membocorkan info.)
        return [
            'unknown email' => ['nobody@boss.local', 'rahasia123'],
            'known email wrong password' => ['staff@boss.local', 'salah'],
            'unknown phone' => ['080000000000', 'rahasia123'],
            'known phone wrong password' => ['081234500999', 'salah'],
            'garbage identifier' => ['not-an-email-or-phone', 'rahasia123'],
        ];
    }

    public function test_inactive_referrer_cannot_log_in_and_gets_the_same_generic_error(): void
    {
        $this->referrerUser(phone: '081234501000', active: false);

        $response = $this->from('/login')->post('/login', ['login' => '081234501000', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors(['login' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts_email_path(): void
    {
        // `config('fortify.limiters.login')` diset → rate limit via route
        // middleware `throttle:login` (HTTP 429), pakai limiter
        // `RateLimiter::for('login')` di FortifyServiceProvider (5/menit
        // per identifier+IP).
        $this->staffUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'staff@boss.local', 'password' => 'salah']);
        }

        $this->post('/login', ['login' => 'staff@boss.local', 'password' => 'rahasia123'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts_phone_path(): void
    {
        $this->referrerUser(phone: '081234501111');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => '081234501111', 'password' => 'salah']);
        }

        $this->post('/login', ['login' => '081234501111', 'password' => 'rahasia123'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_old_referrer_login_post_path_is_rate_limited(): void
    {
        // Jalur compat `/referrer/login` POST tetap punya throttle:6,1 sendiri.
        $this->referrerUser(phone: '081234502222');

        for ($i = 0; $i < 6; $i++) {
            $this->post('/referrer/login', ['phone' => '081234502222', 'password' => 'salah']);
        }

        $this->post('/referrer/login', ['phone' => '081234502222', 'password' => 'rahasia123'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_unified_login_form_renders_the_combined_field(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Email atau Nomor HP')
            ->assertSee('name="login"', false);
    }

    public function test_old_referrer_login_url_redirects_to_the_unified_login(): void
    {
        $this->get('/referrer/login')->assertRedirect(route('login'));
    }

    /**
     * v0.22.1 — CRUD Staff (Manajemen User). Staff yang di-disable harus
     * diblokir login di jalur Fortify utama (`/login`), dengan pesan BEDA
     * dari 'auth.failed' generik (kredensial di titik ini sudah terbukti
     * benar, jadi pesan lebih spesifik tidak membocorkan info baru).
     */
    public function test_a_disabled_staff_account_is_blocked_at_the_main_login_with_a_distinct_message(): void
    {
        $user = $this->staffUser('disabled-staff@boss.local', 'rahasia123');
        $user->update(['is_disabled' => true]);

        $response = $this->from('/login')->post('/login', ['login' => 'disabled-staff@boss.local', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors(['login' => __('auth.account_disabled')]);
        $this->assertGuest();
    }

    /**
     * Jalur KEDUA yang genuinely independen dari Fortify::authenticateUsing()
     * — legacy `/referrer/login` POST controller (guard/login manual sendiri).
     * WAJIB dapat check yang sama, kalau tidak akun disabled bisa lolos lewat
     * rute lama ini (resolver menerima identifier email juga di field `phone`).
     */
    public function test_a_disabled_staff_account_is_also_blocked_at_the_legacy_referrer_login_post_path(): void
    {
        $user = $this->staffUser('disabled-legacy@boss.local', 'rahasia123');
        $user->update(['is_disabled' => true]);

        $response = $this->from('/referrer/login')->post('/referrer/login', ['phone' => 'disabled-legacy@boss.local', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors(['phone' => __('auth.account_disabled')]);
        $this->assertGuest();
    }

    public function test_a_disabled_referrer_account_is_blocked_with_the_distinct_message_too(): void
    {
        $referrer = $this->referrerUser('081234509999', 'rahasia123');
        $referrer->user->update(['is_disabled' => true]);

        $response = $this->from('/login')->post('/login', ['login' => '081234509999', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors(['login' => __('auth.account_disabled')]);
        $this->assertGuest();
    }

    public function test_re_enabling_a_disabled_staff_account_allows_login_again(): void
    {
        $user = $this->staffUser('re-enable@boss.local', 'rahasia123');
        $user->update(['is_disabled' => true]);

        $this->post('/login', ['login' => 're-enable@boss.local', 'password' => 'rahasia123']);
        $this->assertGuest();

        $user->update(['is_disabled' => false]);

        $response = $this->post('/login', ['login' => 're-enable@boss.local', 'password' => 'rahasia123']);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }
}
