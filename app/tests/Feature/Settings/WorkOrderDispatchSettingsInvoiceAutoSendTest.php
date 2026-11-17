<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\WorkOrderDispatchSettings as WorkOrderDispatchSettingsComponent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrderDispatchSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.13.5.3.1 — kartu "Kirim PDF Invoice Otomatis": simpan toggle + Mulai dari
 * tanggal + batas harian, enabled_at terisi saat OFF->ON, validasi cap 1..200,
 * indikator status, izin, isolasi tenant.
 */
class WorkOrderDispatchSettingsInvoiceAutoSendTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'work_orders.manage', 'guard_name' => 'web']));

        return $user;
    }

    public function test_saves_toggle_from_date_and_cap_and_sets_enabled_at(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendEnabled', true)
            ->set('invoicePdfAutoSendFrom', '2026-11-01')
            ->set('invoicePdfAutoSendDailyCap', 50)
            ->call('saveInvoicePdfAutoSend')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettings::forTenant($tenant->id)->fresh();
        $this->assertTrue($settings->invoice_pdf_auto_send_enabled);
        $this->assertSame('2026-11-01', $settings->invoice_pdf_auto_send_from->format('Y-m-d'));
        $this->assertSame(50, $settings->invoice_pdf_auto_send_daily_cap);
        $this->assertNotNull($settings->invoice_pdf_auto_send_enabled_at);
    }

    public function test_enabled_at_is_not_overwritten_on_resave_while_already_on(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendEnabled', true)
            ->call('saveInvoicePdfAutoSend');

        $first = WorkOrderDispatchSettings::forTenant($tenant->id)->fresh()->invoice_pdf_auto_send_enabled_at;

        // Re-save (masih ON), ubah cap → enabled_at tidak berubah.
        Livewire::actingAs($admin)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendDailyCap', 123)
            ->call('saveInvoicePdfAutoSend');

        $second = WorkOrderDispatchSettings::forTenant($tenant->id)->fresh()->invoice_pdf_auto_send_enabled_at;

        $this->assertEquals($first->timestamp, $second->timestamp);
    }

    public function test_cap_out_of_range_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        Livewire::actingAs($admin)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendDailyCap', 0)
            ->call('saveInvoicePdfAutoSend')
            ->assertHasErrors('invoicePdfAutoSendDailyCap');

        Livewire::actingAs($admin)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendDailyCap', 201)
            ->call('saveInvoicePdfAutoSend')
            ->assertHasErrors('invoicePdfAutoSendDailyCap');
    }

    public function test_invalid_from_date_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfAutoSendFrom', 'bukan-tanggal')
            ->call('saveInvoicePdfAutoSend')
            ->assertHasErrors('invoicePdfAutoSendFrom');
    }

    public function test_indicator_status_reflects_switches(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update([
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6287884374939',
            'customer_auto_notify_enabled' => true,
        ]);

        // Mode Uji ON → status "Berhenti: Mode Uji ON".
        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->assertSee('Berhenti: Mode Uji ON');
    }

    public function test_tenant_isolation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenantB->id)->update([
            'invoice_pdf_auto_send_enabled' => true,
            'invoice_pdf_auto_send_daily_cap' => 25,
        ]);

        Livewire::actingAs($this->admin($tenantA))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->assertSet('invoicePdfAutoSendEnabled', false)
            ->assertSet('invoicePdfAutoSendDailyCap', 80);
    }

    public function test_requires_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->assertForbidden();
    }
}
