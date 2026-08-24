<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\CpeSignalHistory;
use App\Models\Tenant;
use App\Services\Network\CpeSignalHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Never hits the real GenieACS API — Http::fake() + Sleep::fake()
 * throughout, same discipline as CpeDeviceStatusSyncServiceTest (v0.7.7).
 * Real behavior confirmed against the actual live fleet (129 online CPE)
 * is recorded in CLAUDE.md's "RX Power History (v0.8.3)" checkpoint
 * section — this file exists to guard the SEND/STAGGER/SKIP/FAILURE logic
 * with fast, deterministic coverage, not to re-prove real GenieACS shapes.
 */
class CpeSignalHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function tenantDevice(array $attributes = []): CpeDevice
    {
        $tenant = Tenant::factory()->create();

        return CpeDevice::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'status' => CpeDeviceStatus::Online,
        ], $attributes));
    }

    private function catalogRow(string $oui, string $productClass, string $path = 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower'): CpeParameterMap
    {
        return CpeParameterMap::factory()->create([
            'oui' => $oui,
            'product_class' => $productClass,
            'parameter_key' => 'rx_power_dbm',
            'parameter_path' => $path,
            'conversion_formula' => 'sff8472_optical_log10',
            // Real catalog rows for this formula all carry this exact
            // scale (see CLAUDE.md's "GenieACS Vendor Parameter Mapping
            // (v0.7.2)") — set explicitly here so this fixture's reverse
            // dBm->raw math below matches what the resolver will actually
            // apply, not the formula's own scale=1.0 fallback default.
            'conversion_params' => ['scale' => 0.0001],
        ]);
    }

    /**
     * @param  array<string, array{oui: string, product_class: string, rx_power: ?float}>  $devices  keyed by genieacs_device_id
     */
    private function fakeGenieAcs(array $devices): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'task-1'], 202),
            'genieacs-nbi:7557/devices?*' => function ($request) use ($devices) {
                $query = json_decode($request->data()['query'] ?? '{}', true);
                $id = $query['_id'] ?? null;

                if ($id === null) {
                    // Bulk identity fetch (empty query) — every device's
                    // _id + _deviceId, same shape as the real bulk call.
                    $all = [];

                    foreach ($devices as $deviceId => $d) {
                        $all[] = [
                            '_id' => $deviceId,
                            '_deviceId' => ['_OUI' => $d['oui'], '_ProductClass' => $d['product_class']],
                        ];
                    }

                    return Http::response($all, 200);
                }

                // Single-device lookup (CpeParameterResolverService's own
                // findDeviceById(), used by resolveDeviceSummary()).
                $d = $devices[$id] ?? null;

                if ($d === null) {
                    return Http::response([], 200);
                }

                $payload = [
                    '_id' => $id,
                    '_deviceId' => ['_OUI' => $d['oui'], '_ProductClass' => $d['product_class']],
                ];

                if ($d['rx_power'] !== null) {
                    $payload['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_GponInterfaceConfig']['RXPower'] = [
                        // sff8472_optical_log10 formula: dBm = 10*log10(raw*0.0001)
                        // — reverse-derived so the resolved value matches
                        // $d['rx_power'] exactly, not asserted as a formula
                        // detail here (that's ParameterConversionServiceTest's job).
                        '_value' => (string) round((10 ** ($d['rx_power'] / 10)) / 0.0001),
                    ];
                }

                return Http::response([$payload], 200);
            },
        ]);
    }

    public function test_offline_devices_are_never_touched(): void
    {
        $this->tenantDevice(['status' => CpeDeviceStatus::Offline, 'genieacs_device_id' => 'OUI-PC-OFFLINE']);

        $this->fakeGenieAcs([]);

        $result = app(CpeSignalHistoryService::class)->syncAll();

        $this->assertSame(0, $result['total_online']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tasks'));
        $this->assertDatabaseCount('cpe_signal_history', 0);
    }

    public function test_device_with_no_catalog_row_is_skipped_entirely(): void
    {
        $device = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-NOCATALOG']);
        // No CpeParameterMap row created for this OUI/ProductClass at all.

        $this->fakeGenieAcs([
            'OUI-PC-NOCATALOG' => ['oui' => 'AABBCC', 'product_class' => 'Unknown', 'rx_power' => -20.0],
        ]);

        $result = app(CpeSignalHistoryService::class)->syncAll();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['recorded']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tasks'));
        $this->assertDatabaseCount('cpe_signal_history', 0);
    }

    public function test_matched_device_gets_a_targeted_refresh_and_a_recorded_row(): void
    {
        $device = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-MATCHED']);
        $this->catalogRow('AABBCC', 'ModelX');

        $this->fakeGenieAcs([
            'OUI-PC-MATCHED' => ['oui' => 'AABBCC', 'product_class' => 'ModelX', 'rx_power' => -18.5],
        ]);

        $result = app(CpeSignalHistoryService::class)->syncAll();

        $this->assertSame(1, $result['recorded']);
        $this->assertSame(0, $result['failed']);

        // Targeted at the parent object only — not the whole WANDevice
        // subtree "Sync Sekarang" uses.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'OUI-PC-MATCHED/tasks')) {
                return false;
            }

            return $request->data()['objectName'] === 'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig';
        });

        $row = CpeSignalHistory::where('cpe_device_id', $device->id)->first();
        $this->assertEqualsWithDelta(-18.5, $row->rx_power_dbm, 0.01);
    }

    public function test_sends_are_staggered_in_chunks_with_a_single_final_read_wait(): void
    {
        // 12 devices, chunk size 5 (private const in the service) -> 3
        // chunks -> exactly 3 inter-chunk sleeps, then exactly ONE 90s
        // read-back wait, never one wait per device/chunk.
        $devices = [];

        for ($i = 1; $i <= 12; $i++) {
            $id = "OUI-PC-STAGGER-{$i}";
            $this->tenantDevice(['genieacs_device_id' => $id]);
            $devices[$id] = ['oui' => 'AABBCC', 'product_class' => 'ModelStagger', 'rx_power' => -20.0];
        }

        $this->catalogRow('AABBCC', 'ModelStagger');
        $this->fakeGenieAcs($devices);

        app(CpeSignalHistoryService::class)->syncAll();

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 3, times: 3);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 90, times: 1);
    }

    public function test_no_online_devices_never_sleeps_at_all(): void
    {
        $this->fakeGenieAcs([]);

        app(CpeSignalHistoryService::class)->syncAll();

        Sleep::assertNeverSlept();
    }

    public function test_one_devices_refresh_failure_does_not_stop_the_rest_of_the_batch(): void
    {
        $ok1 = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-OK-1']);
        $failing = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-FAILING']);
        $ok2 = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-OK-2']);

        $this->catalogRow('AABBCC', 'ModelBatch');

        Http::fake([
            'genieacs-nbi:7557/devices/OUI-PC-FAILING/tasks*' => Http::response('Server Error', 500),
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'task-1'], 202),
            'genieacs-nbi:7557/devices?*' => function ($request) {
                $query = json_decode($request->data()['query'] ?? '{}', true);
                $id = $query['_id'] ?? null;
                $devices = [
                    'OUI-PC-OK-1' => ['oui' => 'AABBCC', 'product_class' => 'ModelBatch', 'rx_power' => -19.0],
                    'OUI-PC-FAILING' => ['oui' => 'AABBCC', 'product_class' => 'ModelBatch', 'rx_power' => -19.0],
                    'OUI-PC-OK-2' => ['oui' => 'AABBCC', 'product_class' => 'ModelBatch', 'rx_power' => -21.0],
                ];

                if ($id === null) {
                    $all = array_map(
                        fn ($deviceId, $d) => ['_id' => $deviceId, '_deviceId' => ['_OUI' => $d['oui'], '_ProductClass' => $d['product_class']]],
                        array_keys($devices),
                        $devices,
                    );

                    return Http::response($all, 200);
                }

                $d = $devices[$id] ?? null;

                if ($d === null) {
                    return Http::response([], 200);
                }

                return Http::response([[
                    '_id' => $id,
                    '_deviceId' => ['_OUI' => $d['oui'], '_ProductClass' => $d['product_class']],
                    'InternetGatewayDevice' => ['WANDevice' => ['1' => ['X_CT-COM_GponInterfaceConfig' => [
                        'RXPower' => ['_value' => (string) round((10 ** ($d['rx_power'] / 10)) / 0.0001)],
                    ]]]],
                ]], 200);
            },
        ]);

        $result = app(CpeSignalHistoryService::class)->syncAll();

        // All 3 targeted (none skipped for lack of catalog) — 1 failed
        // (send error -> forced null), 2 recorded with a real value.
        $this->assertSame(2, $result['recorded']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('cpe_signal_history', 3);

        $failingRow = CpeSignalHistory::where('cpe_device_id', $failing->id)->first();
        $this->assertNull($failingRow->rx_power_dbm);

        $ok1Row = CpeSignalHistory::where('cpe_device_id', $ok1->id)->first();
        $ok2Row = CpeSignalHistory::where('cpe_device_id', $ok2->id)->first();
        $this->assertNotNull($ok1Row->rx_power_dbm);
        $this->assertNotNull($ok2Row->rx_power_dbm);
    }

    public function test_a_matched_device_whose_tree_still_has_no_value_after_refresh_records_a_null_row(): void
    {
        // Real, confirmed-for-real scenario (device #138 in the live
        // fleet, see CLAUDE.md) — refresh sent successfully, but the path
        // still isn't present in the tree by read-back time.
        $device = $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-STILLMISSING']);
        $this->catalogRow('AABBCC', 'ModelMissing');

        $this->fakeGenieAcs([
            'OUI-PC-STILLMISSING' => ['oui' => 'AABBCC', 'product_class' => 'ModelMissing', 'rx_power' => null],
        ]);

        $result = app(CpeSignalHistoryService::class)->syncAll();

        $this->assertSame(0, $result['recorded']);
        $this->assertSame(1, $result['failed']);

        $row = CpeSignalHistory::where('cpe_device_id', $device->id)->first();
        $this->assertNull($row->rx_power_dbm);
    }
}
