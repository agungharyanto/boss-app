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
 * v0.13.5.3 — kartu "Mode Uji Kirim Invoice" di Konfig WA Gateway: simpan
 * (normalisasi 62...), aktif-tanpa-nomor ditolak, izin, isolasi tenant.
 */
class WorkOrderDispatchSettingsInvoiceTestModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'work_orders.manage', 'guard_name' => 'web']));

        return $user;
    }

    public function test_saves_test_mode_and_normalizes_number(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfTestModeEnabled', true)
            ->set('invoicePdfTestRecipient', '087884374939')
            ->call('saveInvoicePdfTestMode')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettings::forTenant($tenant->id)->fresh();
        $this->assertTrue($settings->invoice_pdf_test_mode_enabled);
        $this->assertSame('6287884374939', $settings->invoice_pdf_test_recipient);
    }

    public function test_enabling_without_valid_number_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->set('invoicePdfTestModeEnabled', true)
            ->set('invoicePdfTestRecipient', '')
            ->call('saveInvoicePdfTestMode')
            ->assertHasErrors('invoicePdfTestRecipient');

        $settings = WorkOrderDispatchSettings::forTenant($tenant->id)->fresh();
        $this->assertFalse($settings->invoice_pdf_test_mode_enabled);
    }

    public function test_tenant_isolation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenantB->id)->update([
            'invoice_pdf_test_mode_enabled' => true,
            'invoice_pdf_test_recipient' => '6280000000000',
        ]);

        Livewire::actingAs($this->admin($tenantA))
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->assertSet('invoicePdfTestModeEnabled', false);
    }

    public function test_requires_work_orders_manage_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(WorkOrderDispatchSettingsComponent::class)
            ->assertForbidden();
    }
}
