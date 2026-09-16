<?php

namespace Tests\Feature\Installation;

use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Models\WorkOrderDispatchSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.26.2 — orkestrasi command: self-throttle per tenant dari
 * `work_order_dispatch_settings.command_interval_minutes` +
 * `last_dispatch_run_at`. Logic dispatch/reminder itu SENDIRI sudah
 * ditest terisolasi di `WorkOrderDispatchServiceTest` — di sini fokus ke
 * "kapan command efektif jalan", bukan lagi "apa yang dilakukan saat
 * jalan".
 */
class DispatchWorkOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_a_work_order_when_no_previous_run_exists(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id); // last_dispatch_run_at masih null.
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
        $this->assertNotNull(WorkOrderDispatchSettings::forTenant($tenant->id)->last_dispatch_run_at);
    }

    /**
     * Interval BELUM lewat sejak last_dispatch_run_at — command skip
     * tenant ini sama sekali (WO yang seharusnya sudah bisa di-dispatch
     * tetap dibiarkan menunggu).
     */
    public function test_command_skips_a_tenant_whose_interval_has_not_elapsed_yet(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['command_interval_minutes' => 15, 'last_dispatch_run_at' => now()->subMinutes(5)]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();

        $this->assertNull($workOrder->fresh()->dispatched_at);
    }

    public function test_command_runs_a_tenant_whose_interval_has_elapsed(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['command_interval_minutes' => 15, 'last_dispatch_run_at' => now()->subMinutes(20)]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }

    /**
     * Bukti langsung bahwa command_interval_minutes BENERAN mempengaruhi
     * kapan command efektif jalan — nilai kecil (1 menit) vs besar (60
     * menit) terhadap `last_dispatch_run_at` yang SAMA (5 menit lalu)
     * menghasilkan hasil BERBEDA.
     */
    public function test_changing_command_interval_minutes_changes_whether_the_command_runs(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $lastRun = now()->subMinutes(5);

        $settings->update(['command_interval_minutes' => 1, 'last_dispatch_run_at' => $lastRun]);
        $workOrderShortInterval = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);
        $this->artisan('work-orders:dispatch')->assertSuccessful();
        $this->assertNotNull($workOrderShortInterval->fresh()->dispatched_at, 'Interval 1 menit, 5 menit sudah lewat -> harus jalan.');

        // Reset ke kondisi "baru saja jalan 5 menit lalu" lagi, tapi kali
        // ini interval-nya 60 menit -> belum lewat -> harus skip.
        $settings->fresh()->update(['command_interval_minutes' => 60, 'last_dispatch_run_at' => $lastRun]);
        $workOrderLongInterval = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);
        $this->artisan('work-orders:dispatch')->assertSuccessful();
        $this->assertNull($workOrderLongInterval->fresh()->dispatched_at, 'Interval 60 menit, 5 menit belum lewat -> harus skip.');
    }

    public function test_each_tenant_is_throttled_independently(): void
    {
        $tenantFast = Tenant::factory()->create();
        $tenantSlow = Tenant::factory()->create();

        WorkOrderDispatchSettings::forTenant($tenantFast->id)->update([
            'command_interval_minutes' => 1,
            'last_dispatch_run_at' => now()->subMinutes(5),
        ]);
        WorkOrderDispatchSettings::forTenant($tenantSlow->id)->update([
            'command_interval_minutes' => 60,
            'last_dispatch_run_at' => now()->subMinutes(5),
        ]);

        $woFast = WorkOrder::factory()->create(['tenant_id' => $tenantFast->id, 'scheduled_at' => null, 'dispatched_at' => null]);
        $woSlow = WorkOrder::factory()->create(['tenant_id' => $tenantSlow->id, 'scheduled_at' => null, 'dispatched_at' => null]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();

        $this->assertNotNull($woFast->fresh()->dispatched_at);
        $this->assertNull($woSlow->fresh()->dispatched_at);
    }

    /**
     * Edge case eksplisit diminta: command lambat jalan lewat dari window
     * kecil (dijalankan dobel back-to-back) — hasil akhir harus SAMA
     * seperti sekali jalan, tidak ada efek samping ganda.
     */
    public function test_running_the_command_twice_in_a_row_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();
        $dispatchedAtFirstRun = $workOrder->fresh()->dispatched_at;

        // Paksa interval sangat pendek supaya run kedua TETAP lolos
        // self-throttle (mensimulasikan command yang genuinely dipicu
        // dobel, bukan cuma diblokir throttle) — cek dispatch cycle
        // sendiri (whereNull('dispatched_at')) yang menjaga idempoten di
        // sini, bukan self-throttle-nya.
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'command_interval_minutes' => 1,
            'last_dispatch_run_at' => now()->subMinutes(5),
        ]);

        $this->artisan('work-orders:dispatch')->assertSuccessful();

        $this->assertTrue($dispatchedAtFirstRun->equalTo($workOrder->fresh()->dispatched_at));
    }
}
