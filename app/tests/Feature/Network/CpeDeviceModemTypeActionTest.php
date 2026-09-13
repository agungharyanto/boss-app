<?php

namespace Tests\Feature\Network;

use App\Enums\ModemTypeAssignmentSource;
use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.12.5 (rename dari CpeDeviceWanConfigTemplateActionTest) —
 * POST /api/internal/cpe-devices/{id}/modem-type (override manual Tipe
 * Modem dari Detail Perangkat CPE).
 */
class CpeDeviceModemTypeActionTest extends TestCase
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

    public function test_admin_can_assign_a_modem_type_manually(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/modem-type", ['modem_type_id' => $modemType->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $device->refresh();
        $this->assertSame($modemType->id, $device->modem_type_id);
        $this->assertSame(ModemTypeAssignmentSource::Manual, $device->modem_type_source);
    }

    public function test_admin_can_clear_the_assignment_with_a_null_modem_type_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'modem_type_id' => $modemType->id,
            'modem_type_source' => ModemTypeAssignmentSource::Manual,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/modem-type", ['modem_type_id' => null])
            ->assertOk();

        $device->refresh();
        $this->assertNull($device->modem_type_id);
        $this->assertNull($device->modem_type_source);
    }

    public function test_a_nonexistent_modem_type_id_returns_a_clean_422(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/modem-type", ['modem_type_id' => 999999])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNull($device->fresh()->modem_type_id);
    }

    public function test_a_view_only_user_cannot_assign(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/modem-type", ['modem_type_id' => $modemType->id])
            ->assertForbidden();

        $this->assertNull($device->fresh()->modem_type_id);
    }
}
