<?php

namespace Tests\Feature\Installation;

use App\Enums\WorkOrderStatus;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Installation\WorkOrderDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.26.2 — logic dispatch/reminder murni, terisolasi dari orkestrasi
 * command (self-throttle per tenant dites terpisah di
 * `DispatchWorkOrdersCommandTest`). Semua assertion di sini TIDAK
 * menyentuh WhatsappMessageLog sama sekali — sub-versi ini sengaja belum
 * mengirim notifikasi apa pun (lihat docblock service).
 */
class WorkOrderDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_work_order_with_an_appointment_inside_the_offset_window_gets_dispatched(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        // Janji 90 menit lagi, offset default 120 menit — sudah masuk window.
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => now()->addMinutes(90),
            'dispatched_at' => null,
        ]);

        (new WorkOrderDispatchService)->runDispatchCycle($tenant->id, $settings);

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }

    public function test_a_work_order_with_an_appointment_outside_the_offset_window_is_not_dispatched_yet(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        // Janji 3 jam lagi, offset default 2 jam — belum masuk window.
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => now()->addHours(3),
            'dispatched_at' => null,
        ]);

        (new WorkOrderDispatchService)->runDispatchCycle($tenant->id, $settings);

        $this->assertNull($workOrder->fresh()->dispatched_at);
    }

    public function test_a_work_order_without_an_appointment_is_dispatched_immediately_by_the_cycle(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $count = (new WorkOrderDispatchService)->runDispatchCycle($tenant->id, $settings);

        $this->assertSame(1, $count);
        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }

    public function test_dispatch_cycle_never_touches_a_work_order_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $settingsA = WorkOrderDispatchSettings::forTenant($tenantA->id);
        $otherTenantWorkOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenantB->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        (new WorkOrderDispatchService)->runDispatchCycle($tenantA->id, $settingsA);

        $this->assertNull($otherTenantWorkOrder->fresh()->dispatched_at);
    }

    public function test_dispatch_cycle_is_idempotent_when_run_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => null,
            'dispatched_at' => null,
        ]);

        $service = new WorkOrderDispatchService;
        $first = $service->runDispatchCycle($tenant->id, $settings);
        $dispatchedAtFirstRun = $workOrder->fresh()->dispatched_at;

        // Simulasi command jalan dobel (edge case beban server) — WO yang
        // sudah dispatched_at TIDAK boleh ke-proses lagi.
        $second = $service->runDispatchCycle($tenant->id, $settings);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertTrue($dispatchedAtFirstRun->equalTo($workOrder->fresh()->dispatched_at));
    }

    public function test_a_dispatched_and_not_yet_completed_work_order_gets_a_reminder_inside_the_reminder_window(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $workOrder = WorkOrder::factory()->assigned()->create([
            'tenant_id' => $tenant->id,
            'dispatched_at' => now()->subDay(),
            'last_reminder_sent_at' => null,
        ]);

        $count = (new WorkOrderDispatchService)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertSame(1, $count);
        $this->assertTrue($workOrder->fresh()->last_reminder_sent_at->isToday());
    }

    public function test_no_reminder_outside_the_reminder_window(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        // Window reminder di jam yang jauh dari sekarang.
        $farHour = now()->addHours(6)->format('H:i');
        $settings->update(['reminder_time' => $farHour, 'command_interval_minutes' => 15]);
        $workOrder = WorkOrder::factory()->assigned()->create([
            'tenant_id' => $tenant->id,
            'dispatched_at' => now()->subDay(),
            'last_reminder_sent_at' => null,
        ]);

        $count = (new WorkOrderDispatchService)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertSame(0, $count);
        $this->assertNull($workOrder->fresh()->last_reminder_sent_at);
    }

    public function test_a_work_order_that_already_got_a_reminder_today_is_not_reminded_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $workOrder = WorkOrder::factory()->assigned()->create([
            'tenant_id' => $tenant->id,
            'dispatched_at' => now()->subDays(2),
            'last_reminder_sent_at' => now()->toDateString(),
        ]);

        $count = (new WorkOrderDispatchService)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertSame(0, $count);
    }

    /**
     * WO belum pernah dispatch (`dispatched_at` null) TIDAK boleh dapat
     * reminder — reminder cuma untuk WO yang GENUINELY sudah keluar tapi
     * belum selesai.
     */
    public function test_a_work_order_not_yet_dispatched_never_gets_a_reminder(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'scheduled_at' => now()->addHours(5),
            'dispatched_at' => null,
        ]);

        (new WorkOrderDispatchService)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertNull($workOrder->fresh()->last_reminder_sent_at);
    }

    /**
     * WO yang statusnya sudah Completed atau Cancelled TIDAK boleh dapat
     * reminder lagi, walau `dispatched_at` sudah terisi.
     */
    public function test_a_completed_work_order_never_gets_a_reminder(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => WorkOrderStatus::Completed,
            'dispatched_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        (new WorkOrderDispatchService)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertNull($workOrder->fresh()->last_reminder_sent_at);
    }

    public function test_dispatch_immediately_sets_dispatched_at_right_away(): void
    {
        $workOrder = WorkOrder::factory()->create(['dispatched_at' => null]);

        (new WorkOrderDispatchService)->dispatchImmediately($workOrder);

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }
}
