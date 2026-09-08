<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/internal/cpe-devices/unbound-genieacs — admin/NOC only, JSON
 * {data:[...]}. All GenieACS HTTP faked.
 */
class UnboundGenieacsDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    private const URL = '/api/internal/cpe-devices/unbound-genieacs';

    private function fakeGenieAcs(array $devices): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response($devices, 200)]);
    }

    private function fakeDevice(string $serial): array
    {
        return [
            '_id' => "6C0F0B-H3-2S-{$serial}",
            '_deviceId' => ['_Manufacturer' => 'CMDC', '_OUI' => '6C0F0B', '_ProductClass' => 'H3-2S XPON', '_SerialNumber' => $serial],
            '_registered' => '2026-08-12T05:19:26.820Z',
            '_lastInform' => '2026-09-07T07:55:31.928Z',
            'InternetGatewayDevice' => ['ManagementServer' => ['URL' => ['_value' => 'http://genieacs.bajastu.id:7547']]],
        ];
    }

    public function test_superadmin_gets_the_unbound_list(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        $this->fakeGenieAcs([$this->fakeDevice('UNBOUND01')]);

        $response = $this->actingAs($admin)->getJson(self::URL);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'UNBOUND01')
            ->assertJsonPath('data.0.acs_url', 'http://genieacs.bajastu.id:7547')
            ->assertJsonPath('data.0.boss_state', 'unknown');
    }

    public function test_user_with_only_cpe_devices_view_permission_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo('cpe_devices.view');

        $this->fakeGenieAcs([$this->fakeDevice('UNBOUND01')]);

        $this->actingAs($user)->getJson(self::URL)->assertOk();
    }

    public function test_reseller_membership_alone_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $this->fakeGenieAcs([]);

        $this->actingAs($owner)->getJson(self::URL)->assertForbidden();
    }

    public function test_plain_user_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->fakeGenieAcs([]);

        $this->actingAs($user)->getJson(self::URL)->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_genieacs_unreachable_degrades_to_200_with_an_error_message(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        Http::fake(['*genieacs-nbi*' => Http::response('Service Unavailable', 503)]);

        $response = $this->actingAs($admin)->getJson(self::URL);

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonStructure(['data', 'error']);
    }

    public function test_bound_devices_are_excluded_from_the_response(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        CpeDevice::factory()->create([
            'customer_id' => $customer->id,
            'genieacs_device_id' => '6C0F0B-H3-2S-BOUND0001',
            'serial_number' => 'BOUND0001',
        ]);

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        $this->fakeGenieAcs([$this->fakeDevice('BOUND0001'), $this->fakeDevice('UNBOUND01')]);

        $this->actingAs($admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'UNBOUND01');
    }
}
