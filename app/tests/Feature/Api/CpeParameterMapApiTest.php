<?php

namespace Tests\Feature\Api;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeParameterMapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function nonAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $user->assignRole('billing');

        return $user;
    }

    public function test_admin_can_create_mapping(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/v1/cpe-parameter-maps', [
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10->value,
            'conversion_params' => ['scale' => 0.0001],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.oui', 'F86CE1');
        $response->assertJsonPath('data.is_verified', false);
        $this->assertDatabaseHas('cpe_parameter_maps', [
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
        ]);
    }

    public function test_non_admin_cannot_create_mapping(): void
    {
        $response = $this->actingAs($this->nonAdmin())->postJson('/api/v1/cpe-parameter-maps', [
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'conversion_formula' => CpeParameterConversionFormula::Raw->value,
        ]);

        $response->assertForbidden();
    }

    public function test_duplicate_oui_product_class_parameter_key_is_rejected(): void
    {
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
        ]);

        $response = $this->actingAs($this->admin())->postJson('/api/v1/cpe-parameter-maps', [
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.SomeOther.Path',
            'conversion_formula' => CpeParameterConversionFormula::Raw->value,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['parameter_key']);
    }

    public function test_updating_definition_demotes_verified_row_back_to_unverified(): void
    {
        $map = CpeParameterMap::factory()->verified('some-device-id')->create([
            'parameter_path' => 'InternetGatewayDevice.Old.Path',
        ]);

        $this->assertTrue($map->fresh()->isVerified());

        $response = $this->actingAs($this->admin())->putJson("/api/v1/cpe-parameter-maps/{$map->id}", [
            'parameter_path' => 'InternetGatewayDevice.New.Path',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_verified', false);
        $this->assertNull($map->fresh()->verified_at);
    }

    public function test_update_cannot_directly_set_verified_at(): void
    {
        $map = CpeParameterMap::factory()->create();

        $this->actingAs($this->admin())->putJson("/api/v1/cpe-parameter-maps/{$map->id}", [
            'parameter_path' => $map->parameter_path,
            'verified_at' => now()->toIso8601String(),
            'verified_against_device_id' => 'sneaky-device-id',
        ]);

        $this->assertNull($map->fresh()->verified_at);
    }

    public function test_verify_endpoint_stamps_verification(): void
    {
        $map = CpeParameterMap::factory()->create();

        $response = $this->actingAs($this->admin())->postJson("/api/v1/cpe-parameter-maps/{$map->id}/verify", [
            'device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_verified', true);
        $response->assertJsonPath('data.verified_against_device_id', 'F86CE1-F663NV3a-ZICG296C2E7B');
    }

    public function test_admin_can_delete_mapping(): void
    {
        $map = CpeParameterMap::factory()->create();

        $response = $this->actingAs($this->admin())->deleteJson("/api/v1/cpe-parameter-maps/{$map->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('cpe_parameter_maps', ['id' => $map->id]);
    }

    public function test_resolve_endpoint_returns_converted_values_for_real_verified_zte_mapping(): void
    {
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
            'conversion_params' => ['scale' => 0.0001],
        ]);

        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
                'InternetGatewayDevice' => [
                    'WANDevice' => ['1' => [
                        'X_CT-COM_GponInterfaceConfig' => [
                            'RXPower' => ['_value' => 15],
                        ],
                    ]],
                ],
            ]], 200),
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/cpe-parameter-maps/resolve/F86CE1-F663NV3a-ZICG296C2E7B');

        $response->assertOk();
        $response->assertJsonPath('data.rx_power_dbm.raw_value', 15);
        $this->assertEqualsWithDelta(-28.24, $response->json('data.rx_power_dbm.value'), 0.01);
    }
}
