<?php

namespace Tests\Feature\Network;

use App\Enums\WanConfigTemplateSource;
use App\Models\CpeDevice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v0.12.5 — POST /api/internal/cpe-devices/{id}/wan-config-template
 * (override manual Template Konfig CPE dari Detail Perangkat CPE).
 */
class CpeDeviceWanConfigTemplateActionTest extends TestCase
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

    public function test_admin_can_assign_a_template_manually(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/wan-config-template", ['template_id' => $template->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $device->refresh();
        $this->assertSame($template->id, $device->wan_config_template_id);
        $this->assertSame(WanConfigTemplateSource::Manual, $device->wan_config_template_source);
    }

    public function test_admin_can_clear_the_assignment_with_a_null_template_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'wan_config_template_id' => $template->id,
            'wan_config_template_source' => WanConfigTemplateSource::Manual,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/wan-config-template", ['template_id' => null])
            ->assertOk();

        $device->refresh();
        $this->assertNull($device->wan_config_template_id);
        $this->assertNull($device->wan_config_template_source);
    }

    public function test_a_nonexistent_template_id_returns_a_clean_422(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->postJson("/api/internal/cpe-devices/{$device->id}/wan-config-template", ['template_id' => 999999])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNull($device->fresh()->wan_config_template_id);
    }

    public function test_a_view_only_user_cannot_assign(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->postJson("/api/internal/cpe-devices/{$device->id}/wan-config-template", ['template_id' => $template->id])
            ->assertForbidden();

        $this->assertNull($device->fresh()->wan_config_template_id);
    }
}
