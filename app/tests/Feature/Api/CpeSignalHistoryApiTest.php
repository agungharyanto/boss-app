<?php

namespace Tests\Feature\Api;

use App\Models\CpeDevice;
use App\Models\CpeSignalHistory;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.8.4 — REST wrapper over CpeSignalHistoryQueryService, the WhatsApp-bot
 * integration foothold. See CpeDeviceController::signalHistory()'s own
 * docblock for the {timestamp, rx_power_dbm} response contract.
 */
class CpeSignalHistoryApiTest extends TestCase
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

    public function test_returns_series_in_the_stable_external_shape(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -22.5,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($admin)->getJson("/api/v1/cpe-devices/{$device->id}/signal-history");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.rx_power_dbm', -22.5);
        $this->assertArrayHasKey('timestamp', $response->json('data.0'));
        $this->assertArrayNotHasKey('recorded_at', $response->json('data.0'));
    }

    public function test_range_param_is_mapped_via_the_shared_api_vocabulary(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        // Outside the default Day window (24h), but inside Week (7d) —
        // only visible if ?range=weekly actually widens the query.
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -19.1,
            'recorded_at' => now()->subDays(3),
        ]);

        $default = $this->actingAs($admin)->getJson("/api/v1/cpe-devices/{$device->id}/signal-history");
        $default->assertOk();
        $this->assertCount(0, $default->json('data'));

        $weekly = $this->actingAs($admin)->getJson("/api/v1/cpe-devices/{$device->id}/signal-history?range=weekly");
        $weekly->assertOk();
        $this->assertCount(1, $weekly->json('data'));
    }

    public function test_unknown_range_value_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->getJson("/api/v1/cpe-devices/{$device->id}/signal-history?range=not_a_real_range");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['range']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson("/api/v1/cpe-devices/{$device->id}/signal-history")->assertUnauthorized();
    }

    public function test_a_user_with_no_access_to_this_device_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $stranger = User::factory()->create(['tenant_id' => $tenant->id]);
        $stranger->assignRole('customer_service');

        $this->actingAs($stranger)
            ->getJson("/api/v1/cpe-devices/{$device->id}/signal-history")
            ->assertForbidden();
    }
}
