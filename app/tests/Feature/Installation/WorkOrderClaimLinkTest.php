<?php

namespace Tests\Feature\Installation;

use App\Enums\TechnicianStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\ToolType;
use App\Models\WorkOrder;
use App\Models\WorkOrderToolUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * v0.13.4.1 — Klaim WO via signed-link (public, tanpa login). Lihat
 * WorkOrderClaimController/WorkOrderClaimService's own docblock untuk
 * desain lengkap.
 */
class WorkOrderClaimLinkTest extends TestCase
{
    use RefreshDatabase;

    private function workOrder(Tenant $tenant): WorkOrder
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        return WorkOrder::factory()->forSubscription($subscription)->create(['status' => WorkOrderStatus::Assigned]);
    }

    private function signedClaimUrl(WorkOrder $wo, Technician $technician, ?\DateTimeInterface $expiration = null): string
    {
        return URL::temporarySignedRoute(
            'work-orders.claim.show',
            $expiration ?? now()->addDays(2),
            ['work_order' => $wo->id, 'technician' => $technician->id],
        );
    }

    public function test_valid_signed_link_shows_the_claim_form(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);

        $response = $this->get($this->signedClaimUrl($wo, $technician));

        $response->assertOk();
        $response->assertSee($technician->name);
        $response->assertSee($wo->customer->name);
    }

    public function test_request_without_signature_is_rejected_clearly_not_a_generic_500(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->get("/work-orders/claim/{$wo->id}/{$technician->id}");

        $response->assertForbidden();
    }

    public function test_expired_signature_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);

        $expiredUrl = $this->signedClaimUrl($wo, $technician, now()->subMinute());

        $this->get($expiredUrl)->assertForbidden();
    }

    public function test_technician_a_cannot_use_technician_bs_link(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $technicianA = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $technicianB = Technician::factory()->create(['tenant_id' => $tenant->id]);

        $urlForA = $this->signedClaimUrl($wo, $technicianA);

        // Ganti technician_id di URL tanpa regenerasi signature — persis
        // skenario "pakai link teknisi lain": signature dihitung atas
        // seluruh path+query, jadi mengubah segmen apa pun membuatnya invalid.
        $tamperedUrl = str_replace((string) $technicianA->id, (string) $technicianB->id, $urlForA);

        $this->get($tamperedUrl)->assertForbidden();
    }

    public function test_submit_saves_partners_tools_and_modems_and_leaves_wo_status_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $originalStatus = $wo->status;

        $mainTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $partner = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $toolType = ToolType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        $url = $this->signedClaimUrl($wo, $mainTechnician);

        $response = $this->post($url, [
            'partner_ids' => [$partner->id],
            'tools' => [
                ['tool_type_id' => $toolType->id, 'quantity' => 2, 'technician_id' => $mainTechnician->id],
            ],
            'modems' => [
                ['serial_number' => 'SN12345', 'mac_address' => 'AA:BB:CC:DD:EE:FF', 'technician_id' => $partner->id],
            ],
        ]);

        $response->assertOk();
        $response->assertSee('Klaim berhasil dicatat');

        $this->assertDatabaseHas('work_order_claim_partners', [
            'work_order_id' => $wo->id,
            'technician_id' => $partner->id,
        ]);

        $this->assertDatabaseHas('work_order_tool_usages', [
            'work_order_id' => $wo->id,
            'tool_type_id' => $toolType->id,
            'technician_id' => $mainTechnician->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('work_order_modem_units', [
            'work_order_id' => $wo->id,
            'technician_id' => $partner->id,
            'serial_number' => 'SN12345',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        // Assert eksplisit: WO status genuinely TIDAK berubah oleh klaim.
        $this->assertSame($originalStatus->value, $wo->fresh()->status->value);
    }

    public function test_submit_rejects_a_bearer_technician_id_not_in_main_or_partners(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $mainTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $outsider = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $toolType = ToolType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        $url = $this->signedClaimUrl($wo, $mainTechnician);

        $response = $this->post($url, [
            'partner_ids' => [],
            'tools' => [
                ['tool_type_id' => $toolType->id, 'quantity' => 1, 'technician_id' => $outsider->id],
            ],
            'modems' => [],
        ]);

        $response->assertOk();
        $response->assertDontSee('Klaim berhasil dicatat');

        $this->assertDatabaseMissing('work_order_tool_usages', [
            'work_order_id' => $wo->id,
        ]);
    }

    public function test_submit_with_no_partners_or_items_still_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $mainTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);

        $url = $this->signedClaimUrl($wo, $mainTechnician);

        $response = $this->post($url, [
            'partner_ids' => [],
            'tools' => [],
            'modems' => [],
        ]);

        $response->assertOk();
        $response->assertSee('Klaim berhasil dicatat');
    }

    // ═══════════════════════════════════════════════════════════════
    // v0.13.4.1 amendment — claimed_at/claimed_by_technician_id
    // ═══════════════════════════════════════════════════════════════

    public function test_submitting_a_second_time_to_the_same_wo_is_rejected_and_does_not_duplicate_data(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $mainTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $toolType = ToolType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

        $url = $this->signedClaimUrl($wo, $mainTechnician);

        $payload = [
            'partner_ids' => [],
            'tools' => [
                ['tool_type_id' => $toolType->id, 'quantity' => 1, 'technician_id' => $mainTechnician->id],
            ],
            'modems' => [],
        ];

        // Submit pertama — berhasil.
        $this->post($url, $payload)->assertOk()->assertSee('Klaim berhasil dicatat');
        $this->assertNotNull($wo->fresh()->claimed_at);
        $this->assertSame($mainTechnician->id, $wo->fresh()->claimed_by_technician_id);

        // Submit KEDUA ke link yang SAMA (masih valid secara signature,
        // belum expired) — harus ditolak, TIDAK menghasilkan row kedua.
        $response = $this->post($url, $payload);
        $response->assertOk();
        $response->assertDontSee('Klaim berhasil dicatat');
        $response->assertSee('sudah diklaim');

        $this->assertSame(1, WorkOrderToolUsage::where('work_order_id', $wo->id)->count());
    }

    public function test_a_different_technicians_link_after_already_claimed_shows_read_only_not_a_form(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $firstClaimer = Technician::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teknisi Pertama', 'status' => TechnicianStatus::Active]);
        $secondTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teknisi Kedua', 'status' => TechnicianStatus::Active]);

        // Teknisi pertama klaim duluan lewat link-nya sendiri.
        $this->post($this->signedClaimUrl($wo, $firstClaimer), [
            'partner_ids' => [],
            'tools' => [],
            'modems' => [],
        ])->assertOk();

        // Teknisi KEDUA, pemegang link BERBEDA (mis. dari broadcast dispatch
        // yang sama) untuk WO yang SAMA, buka link-nya sendiri belakangan —
        // harus lihat read-only "sudah diklaim oleh Teknisi Pertama", bukan
        // form kosong yang seolah masih bisa diisi.
        $response = $this->get($this->signedClaimUrl($wo, $secondTechnician));

        $response->assertOk();
        $response->assertSee('sudah diklaim');
        $response->assertSee('Teknisi Pertama');
        $response->assertDontSee('Kirim Klaim');
    }

    public function test_get_after_claimed_shows_partners_tools_and_modems_already_recorded(): void
    {
        $tenant = Tenant::factory()->create();
        $wo = $this->workOrder($tenant);
        $mainTechnician = Technician::factory()->create(['tenant_id' => $tenant->id, 'status' => TechnicianStatus::Active]);
        $partner = Technician::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Partner Kerja', 'status' => TechnicianStatus::Active]);
        $toolType = ToolType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'name' => 'Dropcore']);

        $url = $this->signedClaimUrl($wo, $mainTechnician);
        $this->post($url, [
            'partner_ids' => [$partner->id],
            'tools' => [
                ['tool_type_id' => $toolType->id, 'quantity' => 3, 'technician_id' => $mainTechnician->id],
            ],
            'modems' => [
                ['serial_number' => 'SNXYZ', 'mac_address' => '11:22:33:44:55:66', 'technician_id' => $mainTechnician->id],
            ],
        ])->assertOk();

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Partner Kerja');
        $response->assertSee('Dropcore');
        $response->assertSee('SNXYZ');
    }
}
