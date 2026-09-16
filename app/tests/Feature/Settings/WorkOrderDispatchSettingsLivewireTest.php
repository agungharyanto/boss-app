<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\WorkOrderDispatchSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.26.2 — "Komunikasi > Konfig WA Gateway". `work_orders.manage`
 * (tier-admin, sudah ada) — tidak ada permission baru untuk halaman ini,
 * dikonfirmasi Langkah 0.
 */
class WorkOrderDispatchSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_form_loads_the_defaults_for_a_fresh_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('dispatchOffsetHours', 2)
            ->assertSet('reminderTime', '08:00')
            ->assertSet('commandIntervalMinutes', 15)
            ->assertSet('waGroupName', null);
    }

    public function test_saving_valid_values_persists_them_converted_to_minutes(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 3)
            ->set('reminderTime', '09:30')
            ->set('commandIntervalMinutes', 20)
            ->set('waGroupName', 'Grup Notifikasi Teknisi')
            ->call('save')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettingsModel::forTenant($tenant->id);
        $this->assertSame(180, $settings->dispatch_offset_minutes); // 3 jam -> 180 menit
        $this->assertSame('09:30', $settings->reminder_time);
        $this->assertSame(20, $settings->command_interval_minutes);
        $this->assertSame('Grup Notifikasi Teknisi', $settings->wa_group_name);
    }

    public function test_dispatch_offset_hours_must_be_at_least_one(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 0)
            ->call('save')
            ->assertHasErrors(['dispatchOffsetHours' => 'min']);
    }

    public function test_reminder_time_must_be_a_valid_time_format(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('reminderTime', 'bukan-jam')
            ->call('save')
            ->assertHasErrors(['reminderTime' => 'date_format']);
    }

    public function test_command_interval_minutes_must_be_between_one_and_sixty(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('commandIntervalMinutes', 0)
            ->call('save')
            ->assertHasErrors(['commandIntervalMinutes' => 'min']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('commandIntervalMinutes', 61)
            ->call('save')
            ->assertHasErrors(['commandIntervalMinutes' => 'max']);
    }

    public function test_wa_group_jid_field_is_present_but_disabled(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSee('JID Grup WhatsApp')
            ->assertSee('Belum tersedia — menunggu v0.26.4');
    }

    public function test_a_user_without_work_orders_manage_permission_cannot_access_the_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(WorkOrderDispatchSettings::class)->assertForbidden();
    }

    public function test_settings_are_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenantA))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 5)
            ->call('save');

        $this->assertSame(300, WorkOrderDispatchSettingsModel::forTenant($tenantA->id)->dispatch_offset_minutes);
        $this->assertSame(120, WorkOrderDispatchSettingsModel::forTenant($tenantB->id)->dispatch_offset_minutes);
    }
}
