<?php

namespace Tests\Feature\Network;

use App\Enums\OltPonType;
use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/internal/olt-devices/datatable — same yajra server-side
 * pattern as CpeDeviceDatatableControllerTest, kept intentionally
 * lighter (this module doesn't need per-column sort/search coverage as
 * exhaustive as the CPE list — just prove the endpoint responds, scopes
 * by reseller, and never leaks encrypted credential columns).
 */
class OltDeviceDatatableControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function baseDtParams(): array
    {
        return [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'columns' => [
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
            ],
        ];
    }

    public function test_admin_sees_every_olt_device_and_no_credential_columns_leak(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create(['name' => 'ZTE']);
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'name' => 'C300', 'supported_pon_type' => OltPonType::Gpon]);
        OltDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'nas_id' => $nas->id,
            'olt_model_id' => $model->id,
            'name' => 'OLT Gambir',
            'snmp_ro_community' => 'super-secret',
        ]);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/olt-devices/datatable?'.http_build_query($this->baseDtParams()));

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'OLT Gambir', 'manufacturer_name' => 'ZTE', 'model_name' => 'C300']);
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('snmp_ro_community', $response->getContent());
    }

    public function test_reseller_only_sees_their_own_olt_devices(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $manufacturer = OltManufacturer::factory()->create();
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $manufacturer->id, 'supported_pon_type' => OltPonType::Gpon]);

        OltDevice::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerA->id, 'nas_id' => $nas->id, 'olt_model_id' => $model->id, 'name' => 'OLT A']);
        OltDevice::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id, 'nas_id' => $nas->id, 'olt_model_id' => $model->id, 'name' => 'OLT B']);

        $userA = User::factory()->create(['tenant_id' => $tenant->id]);
        ResellerUser::create([
            'reseller_id' => $resellerA->id,
            'user_id' => $userA->id,
            'role' => ResellerUserRole::Owner,
            'status' => ResellerUserStatus::Active,
        ]);

        $response = $this->actingAs($userA)
            ->getJson('/api/internal/olt-devices/datatable?'.http_build_query($this->baseDtParams()));

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'OLT A']);
        $this->assertStringNotContainsString('OLT B', $response->getContent());
    }
}
