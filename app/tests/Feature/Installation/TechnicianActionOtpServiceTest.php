<?php

namespace Tests\Feature\Installation;

use App\Enums\WhatsappEventType;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Services\Installation\TechnicianActionOtpService;
use App\Services\Installation\TechnicianOtpException;
use App\Services\Shared\ActionOtpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v0.12.1 — thin wrapper OTP untuk Technician. Belum ada pemanggil runtime;
 * di sini diverifikasi service-nya siap pakai (issue → WA ke phone teknisi,
 * verify, isolasi exception jadi TechnicianOtpException, isolasi scope /
 * penerima dari alur Referrer).
 */
class TechnicianActionOtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TechnicianActionOtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();
        $this->otp = app(TechnicianActionOtpService::class);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Halo {recipient_name}, kode {otp_code} untuk {action_label}. Berlaku {otp_minutes} menit.',
            'is_active' => true,
        ]);
    }

    private function technician(string $phone = '081298887777'): Technician
    {
        return Technician::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => $phone,
        ]);
    }

    private function code(int $technicianId, string $scope): string
    {
        return Cache::get("otp:technician:{$technicianId}:{$scope}")['code'];
    }

    public function test_issue_sends_whatsapp_to_the_technician_phone_and_verify_consumes_it(): void
    {
        $technician = $this->technician('081298887777');
        $scope = 'wo_confirm:501';

        $this->otp->issue($technician, $scope, 'konfirmasi pengerjaan Work Order #501');

        $this->assertTrue($this->otp->hasActiveCode($technician, $scope));
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'phone_number' => '6281298887777',
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
        ]);

        $log = WhatsappMessageLog::withoutGlobalScopes()->latest('id')->first();
        $this->assertStringContainsString('Halo '.$technician->name, $log->rendered_content);
        $this->assertStringContainsString('konfirmasi pengerjaan Work Order #501', $log->rendered_content);

        $this->otp->verify($technician, $scope, $this->code($technician->id, $scope));
        $this->assertFalse($this->otp->hasActiveCode($technician, $scope));
    }

    public function test_wrong_code_throws_technician_otp_exception(): void
    {
        $technician = $this->technician();
        $this->otp->issue($technician, 'wo_confirm:1', 'label');

        try {
            $this->otp->verify($technician, 'wo_confirm:1', '000000');
            $this->fail('expected TechnicianOtpException');
        } catch (TechnicianOtpException $e) {
            $this->assertInstanceOf(ActionOtpException::class, $e);
            $this->assertStringContainsString('salah', $e->getMessage());
        }
    }

    public function test_resend_is_rate_limited_after_three_sends(): void
    {
        $technician = $this->technician();

        $this->otp->issue($technician, 'wo_confirm:1', 'label');
        $this->otp->issue($technician, 'wo_confirm:1', 'label');
        $this->otp->issue($technician, 'wo_confirm:1', 'label');

        try {
            $this->otp->issue($technician, 'wo_confirm:1', 'label');
            $this->fail('expected rate limit');
        } catch (TechnicianOtpException $e) {
            $this->assertNotNull($e->retryAfterSeconds);
        }
    }

    public function test_missing_template_throws_delivery_failed_and_stores_no_code(): void
    {
        $otherTenant = Tenant::factory()->create();
        $technician = Technician::factory()->create(['tenant_id' => $otherTenant->id]);

        try {
            $this->otp->issue($technician, 'wo_confirm:1', 'label');
            $this->fail('expected TechnicianOtpException');
        } catch (TechnicianOtpException $e) {
            $this->assertStringContainsString('gagal dikirim', $e->getMessage());
        }

        $this->assertFalse($this->otp->hasActiveCode($technician, 'wo_confirm:1'));
    }

    public function test_technician_and_referrer_codes_do_not_collide_even_with_the_same_id_and_scope(): void
    {
        $technician = Technician::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->otp->issue($technician, 'shared-scope', 'label');

        // Referrer bucket for the same numeric id + scope is untouched.
        $this->assertFalse(
            Cache::has("otp:referrer:{$technician->id}:shared-scope"),
        );
        $this->assertTrue($this->otp->hasActiveCode($technician, 'shared-scope'));

        $related = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'reseller_id' => null]);
        $this->otp->issue($technician, 'with-customer', 'label', $related);
        $log = WhatsappMessageLog::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($related->id, $log->customer_id);
    }
}
