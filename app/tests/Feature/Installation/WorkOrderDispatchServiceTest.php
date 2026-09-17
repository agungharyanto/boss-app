<?php

namespace Tests\Feature\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderStatus;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Installation\WorkOrderDispatchService;
use App\Support\WhatsappPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * v0.26.2 — logic dispatch/reminder murni, terisolasi dari orkestrasi
 * command (self-throttle per tenant dites terpisah di
 * `DispatchWorkOrdersCommandTest`).
 *
 * v0.26.3 — notifikasi WA `WhatsappEventType::WorkOrderDispatched` genuinely
 * dikirim sekarang, di-assert lewat `whatsapp_message_logs`. `Bus::fake()`
 * WAJIB di setUp() — `SendWhatsappMessageJob::applyRateLimitDelay()` genuinely
 * `sleep(5-10s)` tanpa fake (pelajaran sama persis `StaffServiceTest`,
 * v0.22.4) — WhatsappMessageLog tetap tercipta karena row-nya di-insert
 * SEBELUM job di-dispatch, `Bus::fake()` cuma mencegah job itu sendiri
 * genuinely diproses.
 */
class WorkOrderDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function seedTemplate(Tenant $tenant): void
    {
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::WorkOrderDispatched,
            'content' => 'Halo {technician_name}, {status_notice} WO #{work_order_id} — {customer_name} ({customer_address}, {service_type}, {scheduled_at}).',
            'is_active' => true,
        ]);
    }

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

        app(WorkOrderDispatchService::class)->runDispatchCycle($tenant->id, $settings);

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

        app(WorkOrderDispatchService::class)->runDispatchCycle($tenant->id, $settings);

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

        $count = app(WorkOrderDispatchService::class)->runDispatchCycle($tenant->id, $settings);

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

        app(WorkOrderDispatchService::class)->runDispatchCycle($tenantA->id, $settingsA);

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

        $service = app(WorkOrderDispatchService::class);
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

        $count = app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

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

        $count = app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

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

        $count = app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

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

        app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

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

        app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertNull($workOrder->fresh()->last_reminder_sent_at);
    }

    public function test_dispatch_immediately_sets_dispatched_at_right_away(): void
    {
        $workOrder = WorkOrder::factory()->create(['dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }

    // ── v0.26.3 — broadcast WA japri ke semua teknisi aktif ────────────

    public function test_dispatch_immediately_broadcasts_to_every_active_technician_in_the_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $techA = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $techB = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($techA->phone),
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($techB->phone),
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);

        // Dispatch awal — bukan reminder, status_notice tidak mengandung "REMINDER".
        $log = WhatsappMessageLog::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::WorkOrderDispatched->value)
            ->where('phone_number', WhatsappPhone::normalize($techA->phone))
            ->firstOrFail();
        $this->assertStringNotContainsString('REMINDER', $log->rendered_content);
    }

    public function test_broadcast_never_leaks_to_a_technician_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->seedTemplate($tenantA);
        $ownTechnician = Technician::factory()->create(['tenant_id' => $tenantA->id]);
        $otherTechnician = Technician::factory()->create(['tenant_id' => $tenantB->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenantA->id, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($ownTechnician->phone),
        ]);
        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($otherTechnician->phone),
        ]);
    }

    public function test_broadcast_never_reaches_an_inactive_technician(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $active = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $inactive = Technician::factory()->inactive()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($active->phone),
        ]);
        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($inactive->phone),
        ]);
    }

    public function test_dispatch_with_no_active_technician_logs_a_warning_and_does_not_fail(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'dispatched_at' => null]);

        // Nol teknisi aktif di tenant ini — tidak boleh throw, WO tetap dispatched.
        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        $this->assertNotNull($workOrder->fresh()->dispatched_at);
        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
    }

    public function test_reminder_broadcasts_to_all_active_technicians_when_still_unclaimed(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $techA = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $techB = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'technician_id' => null,
            'dispatched_at' => now()->subDay(),
            'last_reminder_sent_at' => null,
        ]);

        app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
        $log = WhatsappMessageLog::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::WorkOrderDispatched->value)
            ->where('phone_number', WhatsappPhone::normalize($techA->phone))
            ->firstOrFail();
        $this->assertStringContainsString('REMINDER', $log->rendered_content);
    }

    public function test_reminder_sends_only_to_the_assigned_technician_when_already_assigned(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['reminder_time' => now()->format('H:i'), 'command_interval_minutes' => 15]);
        $assigned = Technician::factory()->create(['tenant_id' => $tenant->id]);
        // Teknisi lain tetap aktif tapi TIDAK di-assign — tidak boleh ikut kena.
        $other = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->assigned()->create([
            'tenant_id' => $tenant->id,
            'technician_id' => $assigned->id,
            'dispatched_at' => now()->subDay(),
            'last_reminder_sent_at' => null,
        ]);

        app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($assigned->phone),
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);
        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($other->phone),
        ]);
    }

    // ── v0.26.4 — kirim JUGA ke grup WA, kondisional (wa_group_jid) ────

    public function test_dispatch_immediately_also_sends_to_the_group_when_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['wa_group_jid' => '1203group@g.us']);
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        // 1 japri individu + 1 broadcast grup = 2 baris total.
        $this->assertSame(2, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => WhatsappPhone::normalize($technician->phone),
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);
        $this->assertDatabaseHas('whatsapp_message_logs', [
            // JID grup disimpan APA ADANYA — tidak pernah lewat normalize().
            'phone_number' => '1203group@g.us',
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);
    }

    public function test_dispatch_without_a_configured_group_does_not_create_a_group_log(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        // wa_group_jid TIDAK diisi — default null dari forTenant().
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id]);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->dispatchImmediately($workOrder);

        // Cuma japri individu — TIDAK error, TIDAK ada baris grup sama sekali.
        $this->assertSame(1, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderDispatched->value)->count());
        $this->assertNotNull($workOrder->fresh()->dispatched_at);
    }

    public function test_run_dispatch_cycle_also_sends_to_the_group_when_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['wa_group_jid' => '1203group@g.us']);
        Technician::factory()->create(['tenant_id' => $tenant->id]);
        WorkOrder::factory()->create(['tenant_id' => $tenant->id, 'scheduled_at' => null, 'dispatched_at' => null]);

        app(WorkOrderDispatchService::class)->runDispatchCycle($tenant->id, $settings->fresh());

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => '1203group@g.us',
            'event_type' => WhatsappEventType::WorkOrderDispatched->value,
        ]);
    }

    public function test_reminder_cycle_also_sends_to_the_group_when_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seedTemplate($tenant);
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update([
            'reminder_time' => now()->format('H:i'),
            'command_interval_minutes' => 15,
            'wa_group_jid' => '1203group@g.us',
        ]);
        Technician::factory()->create(['tenant_id' => $tenant->id]);
        WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'technician_id' => null,
            'dispatched_at' => now()->subDay(),
            'last_reminder_sent_at' => null,
        ]);

        app(WorkOrderDispatchService::class)->runReminderCycle($tenant->id, $settings->fresh());

        $log = WhatsappMessageLog::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::WorkOrderDispatched->value)
            ->where('phone_number', '1203group@g.us')
            ->firstOrFail();
        $this->assertStringContainsString('REMINDER', $log->rendered_content);
    }
}
