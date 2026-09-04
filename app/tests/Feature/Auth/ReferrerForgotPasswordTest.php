<?php

namespace Tests\Feature\Auth;

use App\Enums\WhatsappEventType;
use App\Livewire\Auth\ReferrerForgotPassword;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ReferrerForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Kode {otp_code} berlaku {otp_minutes} menit — {referrer_name}.',
            'is_active' => true,
        ]);
    }

    private function referrerWithLogin(string $phone, string $password = 'oldpassword123'): Referrer
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'password' => Hash::make($password),
        ]);

        return Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    private function otpCode(int $referrerId, string $scope): string
    {
        return Cache::get("referrer-otp:{$referrerId}:{$scope}")['code'];
    }

    public function test_valid_phone_gets_a_code_and_can_reset_then_login(): void
    {
        $referrer = $this->referrerWithLogin('081299998888');

        $component = Livewire::test(ReferrerForgotPassword::class)
            ->set('phone', '081299998888')
            ->call('submitPhone')
            ->assertSet('stage', 'otp');

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => '6281299998888', // dinormalisasi
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
        ]);

        $code = $this->otpCode($referrer->id, "password_reset:{$referrer->id}");

        $component->set('otp', $code)
            ->call('submitOtp')
            ->assertSet('stage', 'password')
            ->set('password', 'BrandNewPass12345')
            ->set('password_confirmation', 'BrandNewPass12345')
            ->call('submitPassword')
            ->assertSet('stage', 'done')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('BrandNewPass12345', $referrer->user->fresh()->password));

        // The real login flow accepts the new password. v0.9.7 login terpadu:
        // jalur compat `/referrer/login` redirect ke `/`, yang lalu
        // meneruskan Referrer murni ke portalnya.
        $response = $this->post('/referrer/login', [
            'phone' => '081299998888',
            'password' => 'BrandNewPass12345',
        ]);
        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($referrer->user);
        $this->followRedirects($response)->assertOk();
    }

    public function test_unknown_phone_shows_the_same_generic_notice_and_leaks_nothing(): void
    {
        Livewire::test(ReferrerForgotPassword::class)
            ->set('phone', '080000000000')
            ->call('submitPhone')
            ->assertSet('stage', 'otp')
            ->assertSee('Kalau nomor ini terdaftar')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    public function test_unknown_phone_cannot_progress_past_otp(): void
    {
        Livewire::test(ReferrerForgotPassword::class)
            ->set('phone', '080000000000')
            ->call('submitPhone')
            ->set('otp', '123456')
            ->call('submitOtp')
            ->assertHasErrors('otp')
            ->assertSet('stage', 'otp');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $referrer = $this->referrerWithLogin('081277776666');

        Livewire::test(ReferrerForgotPassword::class)
            ->set('phone', '081277776666')
            ->call('submitPhone')
            ->set('otp', '000000')
            ->call('submitOtp')
            ->assertHasErrors('otp')
            ->assertSet('stage', 'otp');
    }

    public function test_password_reset_code_cannot_be_used_for_a_titip_action_and_vice_versa(): void
    {
        $referrer = $this->referrerWithLogin('081255554444');
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        $otp = app(ReferrerActionOtpService::class);

        // Code issued for password_reset...
        $otp->issue($referrer, "password_reset:{$referrer->id}", 'reset password');
        $resetCode = $this->otpCode($referrer->id, "password_reset:{$referrer->id}");

        // ...must NOT verify against a titip scope.
        $this->assertThrows(
            fn () => $otp->verify($referrer, "titip:{$customer->id}", $resetCode),
            ReferrerOtpException::class,
        );

        // And the reverse: a titip code must not verify a password_reset scope.
        $otp->issue($referrer, "titip:{$customer->id}", 'titip', $customer);
        $titipCode = $this->otpCode($referrer->id, "titip:{$customer->id}");

        $this->assertThrows(
            fn () => $otp->verify($referrer, "password_reset:{$referrer->id}", $titipCode),
            ReferrerOtpException::class,
        );

        // Each still verifies against its own scope.
        $otp->verify($referrer, "titip:{$customer->id}", $titipCode);
        $otp->verify($referrer, "password_reset:{$referrer->id}", $resetCode);
    }

    public function test_resend_is_rate_limited_after_three_sends(): void
    {
        $referrer = $this->referrerWithLogin('081233332222');

        $component = Livewire::test(ReferrerForgotPassword::class)
            ->set('phone', '081233332222')
            ->call('submitPhone'); // send #1

        $component->call('resendOtp'); // #2
        $component->call('resendOtp'); // #3
        $component->call('resendOtp')  // #4 → rate limited
            ->assertHasErrors('otp');
    }
}
