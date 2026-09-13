<?php

namespace Tests\Feature\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v0.12.7 Langkah 3 — POST /work-orders/{wo}/request-confirmation + POST
 * /work-orders/{wo}/confirm. Reuse App\Services\Installation\
 * TechnicianActionOtpService (siap sejak v0.12.1) via
 * WorkOrderService::requestConfirmation()/confirmByTechnician() —
 * caller runtime pertamanya. Pola setup WhatsApp template PERSIS
 * TechnicianActionOtpServiceTest (v0.12.1).
 */
class WorkOrderConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Bus::fake();
    }

    /**
     * @return array{0: User, 1: Technician}
     */
    private function technicianUser(Tenant $tenant): array
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('teknisi');
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'phone' => '081298887777']);

        return [$user, $technician];
    }

    private function seedOtpTemplate(Tenant $tenant): void
    {
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Halo {recipient_name}, kode {otp_code} untuk {action_label}.',
            'is_active' => true,
        ]);
    }

    private function workOrder(Tenant $tenant, WorkOrderStatus $status = WorkOrderStatus::InProgress): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return WorkOrder::factory()->forSubscription($subscription)->create(['status' => $status, 'equipment_ready' => true]);
    }

    private function otpCode(int $technicianId, WorkOrder $workOrder): string
    {
        return Cache::get("otp:technician:{$technicianId}:wo-confirm:{$workOrder->id}")['code'];
    }

    public function test_technician_who_claimed_the_work_order_can_request_confirmation_code(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/request-confirmation")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'tenant_id' => $tenant->id,
            'phone_number' => '6281298887777',
        ]);
        $this->assertNotNull($this->otpCode($technician->id, $wo));
    }

    public function test_a_technician_who_has_not_claimed_or_been_assigned_cannot_request_confirmation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant); // not claimed by $user

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/request-confirmation")
            ->assertForbidden();
    }

    public function test_a_user_with_no_linked_technician_cannot_request_confirmation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $wo = $this->workOrder($tenant);

        $this->actingAs($admin)
            ->postJson("/api/v1/work-orders/{$wo->id}/request-confirmation")
            ->assertForbidden();
    }

    public function test_technician_can_confirm_with_the_correct_code(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        [$user, $technician] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/request-confirmation")->assertOk();

        $code = $this->otpCode($technician->id, $wo);

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/confirm", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($wo->fresh()->technician_confirmed_at);
    }

    public function test_confirming_with_the_wrong_code_returns_422_and_does_not_confirm(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/request-confirmation")->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/confirm", ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($wo->fresh()->technician_confirmed_at);
    }

    public function test_confirm_requires_a_code(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedOtpTemplate($tenant);
        [$user] = $this->technicianUser($tenant);
        $wo = $this->workOrder($tenant);
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$wo->id}/claim")->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/work-orders/{$wo->id}/confirm", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }
}
