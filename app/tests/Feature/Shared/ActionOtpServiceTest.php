<?php

namespace Tests\Feature\Shared;

use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Services\Shared\ActionOtpException;
use App\Services\Shared\ActionOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v0.12.1 — service OTP generic (penerima apa pun). Perilaku
 * rate-limit / wrong-attempt / single-use / scope-isolation SAMA PERSIS
 * dengan `ReferrerActionOtpService` v0.9.6 — di sini diuji di level
 * primitif (recipientType + recipientId), bukan lewat model Referrer.
 */
class ActionOtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ActionOtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();
        $this->otp = app(ActionOtpService::class);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Halo {recipient_name}, kode {otp_code} berlaku {otp_minutes} menit untuk {action_label} — {company_name}.',
            'is_active' => true,
        ]);
    }

    private function issue(string $type = 'referrer', string $id = '7', string $scope = 'password_reset:7', ?Customer $customer = null): void
    {
        $this->otp->issue($type, $id, $this->tenant->id, '081200000001', 'Budi', $scope, 'reset password', $customer);
    }

    private function code(string $type, string $id, string $scope): string
    {
        return Cache::get("otp:{$type}:{$id}:{$scope}")['code'];
    }

    public function test_issue_stores_a_code_and_queues_a_whatsapp_message_to_the_recipient_phone(): void
    {
        $this->issue('technician', '3', 'wo_confirm:99');

        $entry = Cache::get('otp:technician:3:wo_confirm:99');
        $this->assertIsArray($entry);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $entry['code']);
        $this->assertSame(0, $entry['wrong_attempts']);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'phone_number' => '6281200000001', // dinormalisasi
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
        ]);
    }

    public function test_rendered_content_fills_recipient_name_and_action_label(): void
    {
        $this->issue('technician', '3', 'wo_confirm:99');

        $log = WhatsappMessageLog::withoutGlobalScopes()->latest('id')->first();
        $this->assertStringContainsString('Halo Budi,', $log->rendered_content);
        $this->assertStringContainsString('untuk reset password', $log->rendered_content);
        $this->assertStringContainsString($this->tenant->name, $log->rendered_content);
    }

    public function test_verify_with_the_right_code_consumes_it_single_use(): void
    {
        $this->issue();
        $code = $this->code('referrer', '7', 'password_reset:7');

        $this->otp->verify('referrer', '7', 'password_reset:7', $code);

        $this->assertFalse($this->otp->hasActiveCode('referrer', '7', 'password_reset:7'));

        // Sudah dikonsumsi — verifikasi kedua gagal.
        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', 'password_reset:7', $code),
            ActionOtpException::class,
        );
    }

    public function test_verify_without_an_active_code_throws_no_code(): void
    {
        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', 'password_reset:7', '123456'),
            ActionOtpException::class,
        );
    }

    public function test_wrong_code_is_rejected_and_counted_then_locks_out_after_max_attempts(): void
    {
        $this->issue();

        for ($i = 1; $i < ActionOtpService::MAX_WRONG_ATTEMPTS; $i++) {
            try {
                $this->otp->verify('referrer', '7', 'password_reset:7', '000000');
                $this->fail('expected ActionOtpException');
            } catch (ActionOtpException $e) {
                $this->assertStringContainsString('salah', $e->getMessage());
            }
            $this->assertSame($i, Cache::get('otp:referrer:7:password_reset:7')['wrong_attempts']);
        }

        // Percobaan ke-MAX menghapus kode.
        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', 'password_reset:7', '000000'),
            ActionOtpException::class,
        );
        $this->assertFalse($this->otp->hasActiveCode('referrer', '7', 'password_reset:7'));

        // Kode asli yang benar pun tidak berlaku lagi.
        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', 'password_reset:7', '999999'),
            ActionOtpException::class,
        );
    }

    public function test_resend_is_rate_limited_after_the_max_within_the_window(): void
    {
        $this->issue(); // #1
        $this->issue(); // #2
        $this->issue(); // #3

        try {
            $this->issue(); // #4 → rate limited
            $this->fail('expected rate limit');
        } catch (ActionOtpException $e) {
            $this->assertNotNull($e->retryAfterSeconds);
            $this->assertStringContainsString('Terlalu banyak', $e->getMessage());
        }
    }

    public function test_rate_limit_is_per_recipient_and_per_scope(): void
    {
        $this->issue('referrer', '7', 'scope-a'); // 1/3 for (referrer,7,scope-a)
        $this->issue('referrer', '7', 'scope-a');
        $this->issue('referrer', '7', 'scope-a'); // 3/3

        // Different scope, same recipient — its own bucket.
        $this->issue('referrer', '7', 'scope-b');
        // Different recipient id — its own bucket.
        $this->issue('referrer', '8', 'scope-a');
        // Different recipient type — its own bucket.
        $this->issue('technician', '7', 'scope-a');

        $this->assertTrue($this->otp->hasActiveCode('referrer', '7', 'scope-b'));
        $this->assertTrue($this->otp->hasActiveCode('referrer', '8', 'scope-a'));
        $this->assertTrue($this->otp->hasActiveCode('technician', '7', 'scope-a'));
    }

    public function test_a_code_for_one_scope_cannot_verify_another_scope(): void
    {
        $this->issue('referrer', '7', 'password_reset:7');
        $resetCode = $this->code('referrer', '7', 'password_reset:7');

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $this->issue('referrer', '7', "titip:{$customer->id}", $customer);
        $titipCode = $this->code('referrer', '7', "titip:{$customer->id}");

        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', "titip:{$customer->id}", $resetCode),
            ActionOtpException::class,
        );
        $this->assertThrows(
            fn () => $this->otp->verify('referrer', '7', 'password_reset:7', $titipCode),
            ActionOtpException::class,
        );

        // Masing-masing tetap valid untuk scope-nya sendiri.
        $this->otp->verify('referrer', '7', "titip:{$customer->id}", $titipCode);
        $this->otp->verify('referrer', '7', 'password_reset:7', $resetCode);
    }

    public function test_missing_template_makes_issue_throw_delivery_failed_and_stores_no_code(): void
    {
        $other = Tenant::factory()->create(); // no template seeded for this tenant

        try {
            $this->otp->issue('referrer', '7', $other->id, '081200000009', 'X', 'scope', 'label');
            $this->fail('expected ActionOtpException');
        } catch (ActionOtpException $e) {
            $this->assertStringContainsString('gagal dikirim', $e->getMessage());
        }

        $this->assertFalse($this->otp->hasActiveCode('referrer', '7', 'scope'));
    }
}
