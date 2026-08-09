<?php

namespace Tests\Feature\Network;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeParameterResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shape confirmed for real against a live ZTE F663NV3.1
     * (F86CE1-F663NV3a-ZICG296C2E7B) — see GenieAcsClientService's own
     * docblock and CpeParameterMapSeeder for the full story.
     */
    private function fakeZteDevice(): array
    {
        return [
            '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
            '_deviceId' => [
                '_Manufacturer' => 'ZICG',
                '_OUI' => 'F86CE1',
                '_ProductClass' => 'F663NV3a',
                '_SerialNumber' => 'ZICG296C2E7B',
            ],
            'InternetGatewayDevice' => [
                'WANDevice' => [
                    '1' => [
                        'X_CT-COM_GponInterfaceConfig' => [
                            'RXPower' => ['_value' => 15, '_type' => 'xsd:int'],
                            'TXPower' => ['_value' => 17100, '_type' => 'xsd:int'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_resolves_mapped_parameters_with_correct_conversion(): void
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
            '*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveForDevice('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertArrayHasKey('rx_power_dbm', $result);
        $this->assertSame(15, $result['rx_power_dbm']['raw_value']);
        $this->assertEqualsWithDelta(-28.24, $result['rx_power_dbm']['value'], 0.01);
        $this->assertNull($result['rx_power_dbm']['error']);
    }

    public function test_returns_empty_array_when_no_mapping_exists_for_device_vendor(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveForDevice('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_device_not_found_in_genieacs(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveForDevice('unknown-device-id');

        $this->assertSame([], $result);
    }

    public function test_reports_error_when_mapped_path_missing_from_devices_tree(): void
    {
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
        ]);

        // Minimal Bootstrap-only tree, no optical DDM object yet — the
        // exact real-world state before a refreshObject task completes
        // (see CLAUDE.md "GenieACS Vendor Parameter Mapping (v0.7.2)").
        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => [
                    '_OUI' => 'F86CE1',
                    '_ProductClass' => 'F663NV3a',
                    '_SerialNumber' => 'ZICG296C2E7B',
                ],
            ]], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveForDevice('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertNull($result['rx_power_dbm']['value']);
        $this->assertNotNull($result['rx_power_dbm']['error']);
    }

    public function test_reports_verified_flag_correctly(): void
    {
        CpeParameterMap::factory()->verified('F86CE1-F663NV3a-ZICG296C2E7B')->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'conversion_formula' => CpeParameterConversionFormula::Sff8472OpticalLog10,
            'conversion_params' => ['scale' => 0.0001],
        ]);

        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveForDevice('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertTrue($result['rx_power_dbm']['verified']);
    }
}
