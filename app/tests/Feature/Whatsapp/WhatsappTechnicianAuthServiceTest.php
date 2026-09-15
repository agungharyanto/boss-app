<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WorkOrderStatus;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Whatsapp\WhatsappTechnicianAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v0.13.3 — resolveAuthorizedTechnicianForActiveWorkOrder(): guard MURNI,
 * tidak ada business logic PSB di sini (itu v0.13.4).
 */
class WhatsappTechnicianAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappTechnicianAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WhatsappTechnicianAuthService::class);
    }

    public function test_matches_technician_with_a_work_order_status_assigned(): void
    {
        $technician = Technician::factory()->create(['phone' => '081234567890']);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::Assigned,
        ]);

        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

        $this->assertNotNull($result);
        $this->assertSame($workOrder->id, $result->id);
    }

    public function test_matches_technician_with_a_work_order_status_in_progress(): void
    {
        $technician = Technician::factory()->create(['phone' => '081234567890']);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::InProgress,
        ]);

        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

        $this->assertNotNull($result);
        $this->assertSame($workOrder->id, $result->id);
    }

    /**
     * Semua 6 status WorkOrder LAIN (selain Assigned/InProgress) TIDAK
     * boleh match — dites eksplisit satu per satu, bukan diasumsikan dari
     * 2 status yang sudah teruji di atas.
     */
    public function test_does_not_match_for_any_other_work_order_status(): void
    {
        $otherStatuses = [
            WorkOrderStatus::PendingOdpCheck,
            WorkOrderStatus::OdpUnavailable,
            WorkOrderStatus::PendingVerification,
            WorkOrderStatus::Ready,
            WorkOrderStatus::Completed,
            WorkOrderStatus::Cancelled,
        ];

        foreach ($otherStatuses as $status) {
            $technician = Technician::factory()->create(['phone' => '081234567890']);
            WorkOrder::factory()->create([
                'tenant_id' => $technician->tenant_id,
                'technician_id' => $technician->id,
                'status' => $status,
            ]);

            $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

            $this->assertNull($result, "Status {$status->value} seharusnya TIDAK match.");
        }
    }

    public function test_technician_phone_valid_but_no_work_order_assigned_at_all_does_not_match(): void
    {
        Technician::factory()->create(['phone' => '081234567890']);
        // Tidak ada WorkOrder sama sekali untuk teknisi ini.

        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

        $this->assertNull($result);
    }

    /**
     * Teknisi berstatus Inactive TIDAK diotorisasi meski nomor cocok dan
     * WO masih Assigned — penambahan guard yang TIDAK diminta eksplisit
     * di instruksi awal tapi ditambahkan sebagai defense-in-depth wajar
     * (dilaporkan, bukan diam-diam).
     */
    public function test_an_inactive_technician_does_not_match_even_with_an_assigned_work_order(): void
    {
        $technician = Technician::factory()->inactive()->create(['phone' => '081234567890']);
        WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::Assigned,
        ]);

        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

        $this->assertNull($result);
    }

    /**
     * Edge case: 1 teknisi punya LEBIH DARI 1 WorkOrder aktif sekaligus.
     * Keputusan (dilaporkan eksplisit, bukan dipilih diam-diam): pilih
     * yang PALING BARU diperbarui (updated_at terbesar).
     */
    public function test_a_technician_with_more_than_one_active_work_order_returns_the_most_recently_updated_one(): void
    {
        $technician = Technician::factory()->create(['phone' => '081234567890']);

        // Eloquent auto-touch updated_at pada SETIAP update() — nilai
        // manual di array update() ditimpa lagi, jadi manipulasi WAKTU
        // GLOBAL (Carbon::setTestNow()) dipakai supaya "assignment 3 jam
        // lalu" vs "assignment 5 menit lalu" genuinely tercermin di
        // updated_at masing-masing baris. $realNow diambil SEKALI di awal
        // (sebelum setTestNow apa pun) — supaya panggilan kedua bukan
        // "relatif dari waktu palsu pertama" (chained).
        $realNow = now();

        Carbon::setTestNow($realNow->copy()->subHours(3));
        $older = WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::Assigned,
        ]);

        Carbon::setTestNow($realNow->copy()->subMinutes(5));
        $newer = WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::InProgress,
        ]);

        Carbon::setTestNow();

        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('081234567890');

        $this->assertNotNull($result);
        $this->assertSame($newer->id, $result->id);
        $this->assertNotSame($older->id, $result->id);
    }

    /**
     * Normalisasi format nomor — technicians.phone tersimpan format lokal
     * "0xxx" di data nyata, tapi nomor MASUK bisa datang format lain
     * (mis. "62xxx") — WhatsappPhone::normalize() dipakai di kedua sisi.
     */
    public function test_matches_regardless_of_local_vs_international_phone_format(): void
    {
        $technician = Technician::factory()->create(['phone' => '081234567890']);
        WorkOrder::factory()->create([
            'tenant_id' => $technician->tenant_id,
            'technician_id' => $technician->id,
            'status' => WorkOrderStatus::Assigned,
        ]);

        // Nomor masuk format internasional "62xxx" — harus tetap match
        // terhadap technicians.phone yang tersimpan format lokal "0xxx".
        $result = $this->service->resolveAuthorizedTechnicianForActiveWorkOrder('6281234567890');

        $this->assertNotNull($result);
    }
}
