<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Customer;
use App\Models\CustomerAutoSendBreaker;
use App\Models\CustomerAutoSendCursor;
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\CustomerAutoSendDeferral;
use App\Models\CustomerAutoSendSettingLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrderDispatchSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.28.5 — tab "Kirim Otomatis" di WhatsappGatewayIndex: render + akses,
 * simpan tiap field + validasi, modal konfirmasi 3 aksi berisiko, audit satu
 * baris per field, tombol Lanjutkan pemutus, indikator. Logika di
 * CustomerAutoSendSettingsService.
 */
class WhatsappGatewayAutoSendTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'whatsapp_gateway.view', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'whatsapp_gateway.manage', 'guard_name' => 'web']));

        return $user;
    }

    // ── Render + akses ───────────────────────────────────────────────────

    public function test_tab_renders_for_admin(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'kirim-otomatis')
            ->assertSee('Notifikasi Otomatis ke Pelanggan')
            ->assertSee('Jadwal Hari Pengingat')
            ->assertSee('Plafon, Jendela Jam')
            ->assertSee('Indikator Hari Ini');
    }

    public function test_tab_nav_button_present_for_admin(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->assertSee('Kirim Otomatis');
    }

    public function test_access_denied_without_whatsapp_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(WhatsappGatewayIndex::class)
            ->assertForbidden();
    }

    // ── Kartu (a): saklar + modal konfirmasi ─────────────────────────────

    public function test_turning_off_auto_notify_saves_without_modal(): void
    {
        $tenant = Tenant::factory()->create(); // default stored ON
        WorkOrderDispatchSettings::forTenant($tenant->id);

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('autoNotifyEnabled', false)
            ->call('requestSaveAutoNotify')
            ->assertSet('confirmAction', null);

        $this->assertFalse((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->customer_auto_notify_enabled);
        $this->assertSame(1, CustomerAutoSendSettingLog::where('tenant_id', $tenant->id)->where('field', 'customer_auto_notify_enabled')->count());
    }

    public function test_turning_on_auto_notify_requires_confirmation_modal(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['customer_auto_notify_enabled' => false]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('autoNotifyEnabled', true)
            ->call('requestSaveAutoNotify')
            ->assertSet('confirmAction', 'auto_notify');

        // Belum tersimpan sampai dikonfirmasi.
        $this->assertFalse((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->customer_auto_notify_enabled);

        $component->call('confirmPending')->assertSet('confirmAction', null);
        $this->assertTrue((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->customer_auto_notify_enabled);
    }

    public function test_turning_off_test_mode_requires_confirmation_modal(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('testModeEnabled', false)
            ->call('requestSaveTestMode')
            ->assertSet('confirmAction', 'test_mode_off');

        $this->assertTrue((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->invoice_pdf_test_mode_enabled);

        $component->call('confirmPending')->assertSet('confirmAction', null);
        $this->assertFalse((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->invoice_pdf_test_mode_enabled);
    }

    public function test_enabling_test_mode_requires_valid_number_and_normalizes(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('testModeEnabled', true)
            ->set('testRecipient', '')
            ->call('requestSaveTestMode')
            ->assertHasErrors('testRecipient');

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('testModeEnabled', true)
            ->set('testRecipient', '087884374939')
            ->call('requestSaveTestMode')
            ->assertHasNoErrors()
            ->assertSet('confirmAction', null); // menyalakan Mode Uji tidak pakai modal

        $this->assertSame('6287884374939', WorkOrderDispatchSettings::forTenant($tenant->id)->invoice_pdf_test_recipient);
    }

    public function test_turning_on_invoice_auto_send_requires_modal_and_sets_enabled_at(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('invoiceAutoSendEnabled', true)
            ->call('requestSaveInvoiceAutoSend')
            ->assertSet('confirmAction', 'invoice_auto_send');

        $this->assertFalse((bool) WorkOrderDispatchSettings::forTenant($tenant->id)->invoice_pdf_auto_send_enabled);

        $component->call('confirmPending');
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $this->assertTrue((bool) $settings->invoice_pdf_auto_send_enabled);
        $this->assertNotNull($settings->invoice_pdf_auto_send_enabled_at);
    }

    public function test_invoice_auto_send_invalid_from_date_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('invoiceAutoSendEnabled', true)
            ->set('invoiceAutoSendFrom', 'bukan-tanggal')
            ->call('requestSaveInvoiceAutoSend')
            ->assertHasErrors('invoiceAutoSendFrom');
    }

    // ── Kartu (b): jadwal hari pengingat ─────────────────────────────────

    public function test_reminder_days_requires_at_least_one(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('reminderDays', [])
            ->call('saveReminderDays')
            ->assertHasErrors('reminderDays');
    }

    public function test_reminder_days_out_of_range_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('reminderDays', ['8'])
            ->call('saveReminderDays')
            ->assertHasErrors('reminderDays.0');
    }

    public function test_reminder_days_valid_persists(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('reminderDays', ['7', '3', '0'])
            ->call('saveReminderDays')
            ->assertHasNoErrors();

        $this->assertSame([7, 3, 0], WorkOrderDispatchSettings::forTenant($tenant->id)->due_reminder_days);
    }

    // ── Kartu (c): kontrol volume ────────────────────────────────────────

    public function test_volume_controls_window_end_must_be_after_start(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('dailyCap', 50)
            ->set('windowStart', '20:00')
            ->set('windowEnd', '08:00')
            ->set('breakerThreshold', 10)
            ->call('saveVolumeControls')
            ->assertHasErrors('windowEnd');
    }

    public function test_volume_controls_cap_and_threshold_validated(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('dailyCap', 0)
            ->set('breakerThreshold', 0)
            ->set('windowStart', '08:00')
            ->set('windowEnd', '20:00')
            ->call('saveVolumeControls')
            ->assertHasErrors(['dailyCap', 'breakerThreshold']);
    }

    public function test_volume_controls_valid_persists(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('dailyCap', 120)
            ->set('windowStart', '07:00')
            ->set('windowEnd', '21:00')
            ->set('breakerThreshold', 5)
            ->call('saveVolumeControls')
            ->assertHasNoErrors();

        $s = WorkOrderDispatchSettings::forTenant($tenant->id);
        $this->assertSame(120, (int) $s->customer_auto_send_daily_cap);
        $this->assertSame('07:00', substr((string) $s->customer_auto_send_window_start, 0, 5));
        $this->assertSame('21:00', substr((string) $s->customer_auto_send_window_end, 0, 5));
        $this->assertSame(5, (int) $s->customer_auto_send_breaker_threshold);
    }

    // ── Audit: satu baris per field yang berubah ─────────────────────────

    public function test_audit_records_exactly_one_row_per_changed_field(): void
    {
        $tenant = Tenant::factory()->create();
        // Default: cap 80, window 08:00-20:00, breaker 10. Ubah 2 field saja.
        WorkOrderDispatchSettings::forTenant($tenant->id);

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('dailyCap', 90)          // berubah
            ->set('windowStart', '08:00')  // sama
            ->set('windowEnd', '20:00')    // sama
            ->set('breakerThreshold', 7)   // berubah
            ->call('saveVolumeControls');

        $logs = CustomerAutoSendSettingLog::where('tenant_id', $tenant->id)->get();
        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing(
            ['customer_auto_send_daily_cap', 'customer_auto_send_breaker_threshold'],
            $logs->pluck('field')->all(),
        );
    }

    public function test_audit_records_nothing_when_no_field_changed(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id); // cap 80, 08:00-20:00, N 10

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('dailyCap', 80)
            ->set('windowStart', '08:00')
            ->set('windowEnd', '20:00')
            ->set('breakerThreshold', 10)
            ->call('saveVolumeControls');

        $this->assertSame(0, CustomerAutoSendSettingLog::where('tenant_id', $tenant->id)->count());
    }

    public function test_audit_row_captures_before_after_and_user(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id); // ON by default
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('autoNotifyEnabled', false)
            ->call('requestSaveAutoNotify');

        $log = CustomerAutoSendSettingLog::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('customer_auto_notify_enabled', $log->field);
        $this->assertSame('1', $log->before);
        $this->assertSame('0', $log->after);
        $this->assertSame($admin->id, $log->user_id);
    }

    // ── Kartu (d): pemutus ───────────────────────────────────────────────

    public function test_resume_button_closes_breaker_and_records_actor(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        CustomerAutoSendBreaker::create([
            'tenant_id' => $tenant->id,
            'session_key' => 'direct',
            'is_open' => true,
            'consecutive_failures' => 10,
            'open_reason' => '10 kegagalan beruntun',
            'opened_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('resumeBreaker');

        $breaker = CustomerAutoSendBreaker::where('tenant_id', $tenant->id)->where('session_key', 'direct')->firstOrFail();
        $this->assertFalse((bool) $breaker->is_open);
        $this->assertSame(0, (int) $breaker->consecutive_failures);
        $this->assertSame($admin->id, (int) $breaker->resumed_by);
    }

    // ── Kartu (f): indikator ─────────────────────────────────────────────

    public function test_indicators_count_from_test_data(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id);

        foreach ([CustomerAutoSendResult::Simulated, CustomerAutoSendResult::Simulated, CustomerAutoSendResult::CapReached] as $r) {
            CustomerAutoSendDecisionLog::create([
                'tenant_id' => $tenant->id,
                'path' => 'customer_suspended_reminder',
                'result' => $r,
                'simulated' => $r === CustomerAutoSendResult::Simulated,
            ]);
        }

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        CustomerAutoSendDeferral::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => CustomerAutoSendDeferral::STATUS_PENDING,
        ]);

        CustomerAutoSendCursor::create([
            'tenant_id' => $tenant->id,
            'session_key' => 'direct',
            'next_slot_at' => now()->addMinutes(5),
            'batch_count' => 2,
        ]);

        $autoSend = Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'kirim-otomatis')
            ->viewData('autoSend');

        $counts = $autoSend['indicators']['decision_counts'];
        $this->assertSame(2, $counts['simulated'] ?? 0);
        $this->assertSame(1, $counts['cap_reached'] ?? 0);
        $this->assertSame(1, $autoSend['indicators']['deferrals_pending']);
        $this->assertNotNull($autoSend['indicators']['next_cursor_slot']);
        $this->assertSame(80, $autoSend['indicators']['cap']);
    }

    public function test_indicators_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        CustomerAutoSendDecisionLog::create([
            'tenant_id' => $tenantB->id,
            'path' => 'invoice_issued',
            'result' => CustomerAutoSendResult::Simulated,
            'simulated' => true,
        ]);

        $autoSend = Livewire::actingAs($this->admin($tenantA))
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'kirim-otomatis')
            ->viewData('autoSend');

        $this->assertSame(0, array_sum($autoSend['indicators']['decision_counts']));
    }
}
