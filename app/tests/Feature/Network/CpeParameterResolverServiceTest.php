<?php

namespace Tests\Feature\Network;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeDeviceModelCapability;
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

    public function test_resolve_device_summary_falls_back_to_wan_ppp_connection_mac_when_no_catalog_row_exists(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] = [
            '1' => [
                'WANPPPConnection' => [
                    '1' => ['MACAddress' => ['_value' => '', '_type' => 'xsd:string']],
                    '2' => ['MACAddress' => ['_value' => 'AA:BB:CC:DD:EE:FF', '_type' => 'xsd:string']],
                ],
            ],
        ];

        Http::fake([
            '*genieacs-nbi*' => Http::response([$device], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveDeviceSummary('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result['mac_address']);
    }

    public function test_resolve_device_summary_mac_address_is_null_when_no_wan_ppp_connection_candidate_has_a_value(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveDeviceSummary('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertNull($result['mac_address']);
    }

    /**
     * Reproduces a real device found in this fleet (0815AE-H3-2s-CMDCA223E8A5,
     * 2026-08-16): WANConnectionDevice instances 2/3/4, no `.1` at all — an
     * earlier version of resolveMacFallback() only ever checked hardcoded
     * WANConnectionDevice/WANPPPConnection 1/2 x 1/2 (mirroring a referral's
     * own fleet), which would have silently missed this device's real MAC
     * entirely. Also covers the sibling all-zero placeholder on
     * WANConnectionDevice.2 sitting right next to the real MAC on .3, which
     * must be skipped rather than returned as-is.
     */
    public function test_resolve_device_summary_mac_walks_non_standard_wan_connection_device_indices(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] = [
            '2' => ['WANPPPConnection' => ['1' => ['MACAddress' => ['_value' => '00:00:00:00:00:00', '_type' => 'xsd:string']]]],
            '3' => ['WANPPPConnection' => ['1' => ['MACAddress' => ['_value' => '08:15:AE:29:15:D1', '_type' => 'xsd:string']]]],
            '4' => ['WANPPPConnection' => []],
        ];

        Http::fake([
            '*genieacs-nbi*' => Http::response([$device], 200),
        ]);

        $result = app(CpeParameterResolverService::class)->resolveDeviceSummary('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame('08:15:AE:29:15:D1', $result['mac_address']);
    }

    /**
     * Reproduces the real "Attached VLANs" shape confirmed on
     * A4F33B-GM219-ZICG298BF2F9 (2026-08-16, built for the standalone CPE
     * detail page): a WANPPPConnection instance's `Name` already encodes
     * the VLAN, no extra declare needed beyond discovering the instance
     * exists.
     */
    public function test_resolve_wan_connections_summary_returns_named_connections_only(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] = [
            '3' => ['WANPPPConnection' => ['1' => [
                'Name' => ['_value' => '1_INTERNET_R_VID_131'],
                'ConnectionStatus' => ['_value' => 'Connected'],
            ]]],
            '4' => ['WANPPPConnection' => ['1' => [
                'Name' => ['_value' => ''],
            ]]],
        ];

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);
        $result = app(CpeParameterResolverService::class)->resolveWanConnectionsSummary('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertCount(1, $result);
        $this->assertSame('1_INTERNET_R_VID_131', $result[0]['name']);
        $this->assertSame('PPPoE', $result[0]['type']);
        $this->assertSame('Connected', $result[0]['status']);
    }

    public function test_resolve_pppoe_connection_returns_first_instance_with_non_empty_username(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] = [
            '2' => ['WANPPPConnection' => ['1' => ['Username' => ['_value' => '']]]],
            '3' => ['WANPPPConnection' => ['1' => [
                'Name' => ['_value' => '1_INTERNET_R_VID_131'],
                'Username' => ['_value' => '083128836762'],
                'Password' => ['_value' => ''],
            ]]],
        ];

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $result = app(CpeParameterResolverService::class)->resolvePppoeConnection('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame('083128836762', $result['username']);
        $this->assertSame('1_INTERNET_R_VID_131', $result['name']);
        $this->assertSame('', $result['password']);
    }

    public function test_resolve_pppoe_connection_returns_null_when_no_username_anywhere(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200)]);

        $result = app(CpeParameterResolverService::class)->resolvePppoeConnection('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertNull($result);
    }

    /**
     * 2026-08-19: no cpe_device_model_capabilities row for this combo, so
     * it falls back to the default 4 slots — real data at 1/4 fills those
     * rows, index 2/3 are padded as empty placeholders, and index 5 (real
     * data, but beyond the default max) is still appended rather than
     * dropped.
     */
    public function test_resolve_wlan_configurations_pads_missing_slots_up_to_the_default_max_and_still_shows_real_data_beyond_it(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['LANDevice'] = ['1' => ['WLANConfiguration' => [
            '1' => ['SSID' => ['_value' => 'HomeWifi'], 'Enable' => ['_value' => true]],
            '4' => ['SSID' => ['_value' => 'TOKEN WIFI'], 'Enable' => ['_value' => true]],
            '5' => ['SSID' => ['_value' => 'HomeWifi-5G'], 'Enable' => ['_value' => false]],
        ]]];

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $result = app(CpeParameterResolverService::class)->resolveWlanConfigurations('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertCount(5, $result);
        $this->assertSame(['1', 'HomeWifi', true], [$result[0]['index'], $result[0]['ssid'], $result[0]['enabled']]);
        $this->assertSame(['2', null, null], [$result[1]['index'], $result[1]['ssid'], $result[1]['enabled']]);
        $this->assertSame(['3', null, null], [$result[2]['index'], $result[2]['ssid'], $result[2]['enabled']]);
        $this->assertSame(['4', 'TOKEN WIFI', true], [$result[3]['index'], $result[3]['ssid'], $result[3]['enabled']]);
        $this->assertSame(['5', 'HomeWifi-5G', false], [$result[4]['index'], $result[4]['ssid'], $result[4]['enabled']]);
    }

    public function test_resolve_wlan_configurations_returns_empty_array_when_no_real_ssid_discovered_at_all(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200)]);

        $result = app(CpeParameterResolverService::class)->resolveWlanConfigurations('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame([], $result);
    }

    /**
     * A real cpe_device_model_capabilities row (Bagian C, 2026-08-19)
     * overrides the default padding width — matches the H3-2s family's
     * real, empirically-confirmed [1, 5] gap pattern (see
     * CpeDeviceModelCapabilitySeeder).
     */
    public function test_resolve_wlan_configurations_uses_the_capability_catalogs_max_slots_when_a_row_exists(): void
    {
        CpeDeviceModelCapability::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'max_ssid_slots' => 5,
            'supports_5g' => true,
        ]);

        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['LANDevice'] = ['1' => ['WLANConfiguration' => [
            '1' => ['SSID' => ['_value' => 'HAROR STEAM'], 'Enable' => ['_value' => true]],
            '5' => ['SSID' => ['_value' => 'HAROR STEAM'], 'Enable' => ['_value' => true]],
        ]]];

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $result = app(CpeParameterResolverService::class)->resolveWlanConfigurations('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertCount(5, $result);
        $this->assertSame(['2', null, null], [$result[1]['index'], $result[1]['ssid'], $result[1]['enabled']]);
        $this->assertSame(['3', null, null], [$result[2]['index'], $result[2]['ssid'], $result[2]['enabled']]);
        $this->assertSame(['4', null, null], [$result[3]['index'], $result[3]['ssid'], $result[3]['enabled']]);
        $this->assertSame('5', $result[4]['index']);
        $this->assertSame('HAROR STEAM', $result[4]['ssid']);
    }

    public function test_resolve_ethernet_ports_returns_empty_array_when_device_has_none(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeZteDevice()], 200)]);

        $result = app(CpeParameterResolverService::class)->resolveEthernetPorts('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertSame([], $result);
    }

    /**
     * Shape confirmed for real on a Huawei EG8141A5
     * (00259E-EG8141A5-48575443796B91A7, 2026-08-16) — LANEthernetInterfaceConfig
     * exists on some devices in this fleet despite nothing in default.js
     * ever declaring/discovering it.
     */
    public function test_resolve_ethernet_ports_returns_discovered_ports(): void
    {
        $device = $this->fakeZteDevice();
        $device['InternetGatewayDevice']['LANDevice'] = ['1' => ['LANEthernetInterfaceConfig' => [
            '1' => [
                'Name' => ['_value' => 'eth0:1'],
                'Enable' => ['_value' => true],
                'Status' => ['_value' => 'NoLink'],
                'MACAddress' => ['_value' => '5C:E7:47:22:51:7D'],
            ],
        ]]];

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $result = app(CpeParameterResolverService::class)->resolveEthernetPorts('F86CE1-F663NV3a-ZICG296C2E7B');

        $this->assertCount(1, $result);
        $this->assertSame('eth0:1', $result[0]['name']);
        $this->assertSame('NoLink', $result[0]['status']);
        $this->assertTrue($result[0]['enabled']);
        $this->assertSame('5C:E7:47:22:51:7D', $result[0]['mac_address']);
    }
}
