<?php

namespace Tests\Feature\Auth;

use App\Enums\WhatsappEventType;
use App\Livewire\Auth\StaffForgotPassword;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Services\StaffActionOtpService;
use App\Services\StaffOtpException;
use App\Support\WhatsappPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.22.4 — "Lupa Password" khusus akun STAFF. Pola test mirror persis
 * `ReferrerForgotPasswordTest`, disesuaikan lookup `users.phone` +
 * `whereHas('roles')`/`is_disabled` guard.
 */
class StaffForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();

        // Reuse WhatsappEventType::ReferrerActionOtp — StaffActionOtpService
        // dikonfirmasi Agung TIDAK butuh event type baru (event ini sudah
        // generic by design sejak v0.9.6).
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Kode {otp_code} berlaku {otp_minutes} menit — {recipient_name}.',
            'is_active' => true,
        ]);
    }

    /**
     * `phone` DISIMPAN TERNORMALISASI — mirror persis apa yang genuinely
     * dilakukan `StaffService::create()` (lihat `WhatsappPhone::normalize()`)
     * di produksi, sama disiplin `UnifiedLoginTest::staffUser()`. Beda dari
     * `referrers.phone` (raw, tidak dinormalisasi) — `users.phone` SELALU
     * `62xxx` sejak v0.22.2.
     */
    private function staffWithLogin(string $phone, string $password = 'oldpassword123', string $role = 'noc', bool $disabled = false): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => WhatsappPhone::normalize($phone),
            'password' => Hash::make($password),
            'is_disabled' => $disabled,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function otpCode(int $userId, string $scope): string
    {
        return Cache::get("otp:staff:{$userId}:{$scope}")['code'];
    }

    public function test_valid_phone_gets_a_code_and_can_reset_then_login(): void
    {
        $staff = $this->staffWithLogin('081299998888');

        $component = Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081299998888')
            ->call('submitPhone')
            ->assertSet('stage', 'otp');

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => '6281299998888', // dinormalisasi
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
        ]);

        $code = $this->otpCode($staff->id, "staff_password_reset:{$staff->id}");

        $component->set('otp', $code)
            ->call('submitOtp')
            ->assertSet('stage', 'password')
            ->set('password', 'BrandNewPass12345')
            ->set('password_confirmation', 'BrandNewPass12345')
            ->call('submitPassword')
            ->assertSet('stage', 'done')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('BrandNewPass12345', $staff->fresh()->password));

        $response = $this->post('/login', [
            'login' => '081299998888',
            'password' => 'BrandNewPass12345',
        ]);
        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
    }

    /**
     * v0.22.6 — staff yang reset password lewat OTP sudah MEMILIH SENDIRI
     * password barunya (beda dari password random StaffService::create())
     * — tidak perlu dipaksa ganti lagi begitu login. Reproduksi kasus nyata:
     * staff baru (must_change_password=true) yang lupa password sementara-
     * nya SEBELUM sempat login sama sekali, lalu reset lewat jalur ini.
     */
    public function test_reset_via_otp_sets_must_change_password_to_false(): void
    {
        $staff = $this->staffWithLogin('081277778888');
        $staff->forceFill(['must_change_password' => true])->save();

        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081277778888')
            ->call('submitPhone')
            ->assertSet('stage', 'otp')
            ->set('otp', $this->otpCode($staff->id, "staff_password_reset:{$staff->id}"))
            ->call('submitOtp')
            ->assertSet('stage', 'password')
            ->set('password', 'ResetSendiri12345')
            ->set('password_confirmation', 'ResetSendiri12345')
            ->call('submitPassword')
            ->assertSet('stage', 'done')
            ->assertHasNoErrors();

        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_unknown_phone_shows_the_same_generic_notice_and_leaks_nothing(): void
    {
        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '080000000000')
            ->call('submitPhone')
            ->assertSet('stage', 'otp')
            ->assertSee('Kalau nomor ini terdaftar')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    /**
     * Akun Referrer portal (zero-role, `User::factory()->create()` tanpa
     * `assignRole()`) TIDAK boleh bisa reset password lewat jalur STAFF
     * ini — pelajaran insiden Kamisem (v0.22.1), sama filter
     * `whereHas('roles')` yang dipakai `StaffIndex::render()`.
     */
    public function test_a_zero_role_referrer_portal_account_gets_the_same_generic_notice_and_cannot_reset(): void
    {
        User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => WhatsappPhone::normalize('081277771234'),
        ]);
        // Sengaja TIDAK assignRole() apa pun — supaya test ini benar-benar
        // menguji filter whereHas('roles'), bukan kebetulan gagal cuma
        // karena format phone tidak cocok (phone TETAP dinormalisasi sama
        // seperti staffWithLogin()).

        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081277771234')
            ->call('submitPhone')
            ->assertSet('stage', 'otp')
            ->assertSee('Kalau nomor ini terdaftar');

        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    /**
     * Staff yang `is_disabled` tidak boleh login sama sekali — konsisten,
     * juga tidak boleh reset password sendiri.
     */
    public function test_a_disabled_staff_account_gets_the_same_generic_notice_and_cannot_reset(): void
    {
        $this->staffWithLogin('081266665555', disabled: true);

        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081266665555')
            ->call('submitPhone')
            ->assertSet('stage', 'otp');

        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    public function test_unknown_phone_cannot_progress_past_otp(): void
    {
        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '080000000000')
            ->call('submitPhone')
            ->set('otp', '123456')
            ->call('submitOtp')
            ->assertHasErrors('otp')
            ->assertSet('stage', 'otp');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->staffWithLogin('081277776666');

        Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081277776666')
            ->call('submitPhone')
            ->set('otp', '000000')
            ->call('submitOtp')
            ->assertHasErrors('otp')
            ->assertSet('stage', 'otp');
    }

    /**
     * Kode reset password staff & reset password referrer punya
     * recipientType berbeda ('staff' vs 'referrer') di cache key
     * `ActionOtpService` — genuinely terisolasi, bukan cuma scope string
     * yang kebetulan beda.
     */
    public function test_staff_and_referrer_password_reset_codes_are_isolated_even_with_the_same_user_id(): void
    {
        $staff = $this->staffWithLogin('081255554444');

        $otp = app(StaffActionOtpService::class);
        $otp->issue($staff, "staff_password_reset:{$staff->id}", 'reset password akun staff BOSS App');
        $code = $this->otpCode($staff->id, "staff_password_reset:{$staff->id}");

        // Cache key recipientType='referrer' dengan id yang SAMA (kebetulan
        // nomor sama dengan $staff->id) tidak akan pernah ketemu kode ini —
        // dibuktikan langsung lewat Cache, bukan cuma dipercaya dari desain.
        $this->assertNull(Cache::get("otp:referrer:{$staff->id}:staff_password_reset:{$staff->id}"));
        $this->assertNotNull(Cache::get("otp:staff:{$staff->id}:staff_password_reset:{$staff->id}"));
        $this->assertSame($code, $this->otpCode($staff->id, "staff_password_reset:{$staff->id}"));
    }

    public function test_resend_is_rate_limited_after_three_sends(): void
    {
        $this->staffWithLogin('081233332222');

        $component = Livewire::test(StaffForgotPassword::class)
            ->set('phone', '081233332222')
            ->call('submitPhone'); // send #1

        $component->call('resendOtp'); // #2
        $component->call('resendOtp'); // #3
        $component->call('resendOtp')  // #4 → rate limited
            ->assertHasErrors('otp');
    }

    public function test_action_otp_exception_wraps_as_staff_otp_exception(): void
    {
        $staff = $this->staffWithLogin('081244443333');
        $otp = app(StaffActionOtpService::class);

        $this->assertThrows(
            fn () => $otp->verify($staff, "staff_password_reset:{$staff->id}", '000000'),
            StaffOtpException::class,
        );
    }
}
