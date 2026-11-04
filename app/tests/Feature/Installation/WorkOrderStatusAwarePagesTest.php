<?php

namespace Tests\Feature\Installation;

use App\Enums\TechnicianStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderNotClaimableException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * v0.13.4.5 — halaman klaim/aktivasi/tutup WO SADAR STATUS. Matriks
 * status × link: tiap status WO menampilkan form / kartu status yang benar
 * untuk tiap jenis link (claim, aktivasi, tutup). Plus: nomor telepon
 * pelanggan tidak pernah bocor ke HTML publik, dan klaim WO terminal ditolak
 * di service web (race-safe). Lihat WorkOrderClaimController's own docblock.
 */
class WorkOrderStatusAwarePagesTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_PHONE = '081234567890';

    /**
     * @return array{0: WorkOrder, 1: Technician} WO (+ customer bertelepon) & teknisi pengklaim
     */
    private function makeWorkOrder(
        WorkOrderStatus $status = WorkOrderStatus::Assigned,
        bool $claimed = false,
        bool $claimedBySelf = true,
    ): array {
        $wo = WorkOrder::factory()->create(['status' => $status]);
        $claimer = Technician::factory()->create(['tenant_id' => $wo->tenant_id, 'status' => TechnicianStatus::Active, 'name' => 'Teknisi Pengklaim']);

        Customer::withoutGlobalScopes()->where('id', $wo->customer_id)->update([
            'name' => 'Pelanggan Uji',
            'address' => 'Jl. Contoh No. 1',
            'phone_number' => self::CUSTOMER_PHONE,
        ]);

        if ($claimed) {
            $actualClaimer = $claimedBySelf
                ? $claimer
                : Technician::factory()->create(['tenant_id' => $wo->tenant_id, 'status' => TechnicianStatus::Active, 'name' => 'Teknisi Lain']);
            $wo->update(['claimed_at' => now(), 'claimed_by_technician_id' => $actualClaimer->id]);
        }

        return [$wo->fresh(), $claimer];
    }

    private function signed(string $route, WorkOrder $wo, Technician $tech): string
    {
        return URL::temporarySignedRoute($route, now()->addDays(2), ['work_order' => $wo->id, 'technician' => $tech->id]);
    }

    // ── CLAIM ───────────────────────────────────────────────────────────

    public function test_claim_available_statuses_show_the_form(): void
    {
        foreach ([WorkOrderStatus::PendingOdpCheck, WorkOrderStatus::Ready, WorkOrderStatus::Assigned] as $status) {
            [$wo, $tech] = $this->makeWorkOrder(status: $status);
            $this->get($this->signed('work-orders.claim.show', $wo, $tech))
                ->assertOk()
                ->assertSee('Ambil WO ini')
                ->assertSee('Pelanggan Uji');
        }
    }

    public function test_claim_claimed_by_other_shows_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: false);
        $this->get($this->signed('work-orders.claim.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Sudah Diambil')
            ->assertSee('Teknisi Lain')
            ->assertDontSee('Ambil WO ini');
    }

    public function test_claim_claimed_by_me_shows_identity_and_activation_button(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: true);
        $this->get($this->signed('work-orders.claim.show', $wo, $tech))
            ->assertOk()
            ->assertSee('sudah Anda klaim')
            ->assertSee('Lanjut Aktivasi Pelanggan')
            ->assertDontSee('Ambil WO ini');
    }

    public function test_claim_completed_shows_selesai_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Completed, claimed: true, claimedBySelf: false);
        $this->get($this->signed('work-orders.claim.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Selesai')
            ->assertDontSee('Ambil WO ini');
    }

    public function test_claim_cancelled_shows_dibatalkan_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Cancelled);
        $this->get($this->signed('work-orders.claim.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Dibatalkan')
            ->assertDontSee('Ambil WO ini');
    }

    // ── AKTIVASI ─────────────────────────────────────────────────────────

    public function test_activate_not_claimed_shows_belum_diambil_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: false);
        $this->get($this->signed('work-orders.activate.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Belum Diambil')
            ->assertDontSee('name="ssid"', false);
    }

    public function test_activate_claimed_by_other_shows_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: false);
        $this->get($this->signed('work-orders.activate.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Sudah Diambil')
            ->assertDontSee('name="ssid"', false);
    }

    public function test_activate_claimed_by_me_shows_form(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: true);
        $this->get($this->signed('work-orders.activate.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Aktivasi Pelanggan')
            ->assertSee('name="ssid"', false);
    }

    public function test_activate_completed_and_cancelled_show_terminal_cards(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Completed, claimed: true);
        $this->get($this->signed('work-orders.activate.show', $wo, $tech))
            ->assertOk()->assertSee('Work Order Selesai')->assertDontSee('name="ssid"', false);

        [$wo2, $tech2] = $this->makeWorkOrder(status: WorkOrderStatus::Cancelled, claimed: true);
        $this->get($this->signed('work-orders.activate.show', $wo2, $tech2))
            ->assertOk()->assertSee('Work Order Dibatalkan')->assertDontSee('name="ssid"', false);
    }

    // ── TUTUP ────────────────────────────────────────────────────────────

    public function test_close_not_claimed_shows_belum_diambil_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: false);
        $this->get($this->signed('work-orders.close.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Belum Diambil')
            ->assertDontSee('name="signature"', false);
    }

    public function test_close_claimed_by_other_shows_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::InProgress, claimed: true, claimedBySelf: false);
        $this->get($this->signed('work-orders.close.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Sudah Diambil')
            ->assertDontSee('name="signature"', false);
    }

    public function test_close_claimed_by_me_but_not_in_progress_shows_belum_diaktivasi_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: true);
        $this->get($this->signed('work-orders.close.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Belum Bisa Ditutup')
            ->assertSee('Lanjut ke Aktivasi Pelanggan')
            ->assertDontSee('name="signature"', false);
    }

    public function test_close_completed_shows_selesai_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Completed, claimed: true);
        $this->get($this->signed('work-orders.close.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Selesai')
            ->assertDontSee('name="signature"', false);
    }

    public function test_close_cancelled_shows_dibatalkan_card(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Cancelled, claimed: true);
        $this->get($this->signed('work-orders.close.show', $wo, $tech))
            ->assertOk()
            ->assertSee('Work Order Dibatalkan')
            ->assertDontSee('name="signature"', false);
    }

    // ── TELEPON PELANGGAN TIDAK BOCOR ─────────────────────────────────────

    public function test_customer_phone_number_never_appears_on_any_public_page(): void
    {
        // Form tersedia (claim).
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned);
        $this->get($this->signed('work-orders.claim.show', $wo, $tech))->assertDontSee(self::CUSTOMER_PHONE);

        // Kartu "diklaim orang lain" (claim).
        [$wo2, $tech2] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: false);
        $this->get($this->signed('work-orders.claim.show', $wo2, $tech2))->assertDontSee(self::CUSTOMER_PHONE);

        // Form aktivasi (diklaim sendiri).
        [$wo3, $tech3] = $this->makeWorkOrder(status: WorkOrderStatus::Assigned, claimed: true, claimedBySelf: true);
        $this->get($this->signed('work-orders.activate.show', $wo3, $tech3))->assertDontSee(self::CUSTOMER_PHONE);

        // Kartu terminal SELESAI (tutup).
        [$wo4, $tech4] = $this->makeWorkOrder(status: WorkOrderStatus::Completed, claimed: true);
        $this->get($this->signed('work-orders.close.show', $wo4, $tech4))->assertDontSee(self::CUSTOMER_PHONE);
    }

    // ── CELAH KLAIM TERMINAL DITUTUP DI SERVICE WEB ──────────────────────

    public function test_web_claim_service_rejects_a_completed_work_order(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Completed);

        $this->expectException(WorkOrderNotClaimableException::class);
        app(WorkOrderClaimService::class)->submit($wo, $tech, [], [], []);
    }

    public function test_web_claim_service_rejects_a_cancelled_work_order(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Cancelled);

        $this->expectException(WorkOrderNotClaimableException::class);
        app(WorkOrderClaimService::class)->submit($wo, $tech, [], [], []);
    }

    public function test_claim_submit_on_completed_wo_shows_card_and_does_not_claim(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Completed);

        $this->post($this->signed('work-orders.claim.show', $wo, $tech), [
            'partner_ids' => [], 'tools' => [], 'modems' => [],
        ])->assertOk()->assertSee('Work Order Selesai')->assertDontSee('Klaim berhasil dicatat');
    }
}
