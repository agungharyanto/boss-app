<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Enums\Tr069Root;
use App\Models\CpeDevice;
use App\Models\Tenant;
use App\Services\Network\CpeDeviceStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CpeDeviceStatusSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function genieAcsDevice(string $id, ?string $connectionRequestUrl, ?string $lastInform): array
    {
        $device = ['_id' => $id, '_lastInform' => $lastInform];

        if ($connectionRequestUrl !== null) {
            $device['InternetGatewayDevice'] = [
                'ManagementServer' => [
                    'ConnectionRequestURL' => ['_value' => $connectionRequestUrl],
                ],
            ];
        }

        return $device;
    }

    /**
     * @param  array<int, array<string, mixed>>  $initialQueryDevices  what the FIRST GET /devices (pre-probe) returns
     * @param  array<int, array<string, mixed>>|null  $recheckQueryDevices  what the SECOND GET /devices (post-probe recheck) returns; null reuses the initial response for both calls (no stale devices expected to need a recheck)
     */
    private function fakeGenieAcs(array $initialQueryDevices, ?array $recheckQueryDevices = null): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'task-1'], 202),
            // Deliberately `devices?*` (query string), not the broader
            // `devices*` — that would ALSO match `/devices/{id}/tasks?...`
            // (Str::is's `*` matches slashes too), so Http::fake's stub
            // matcher (which evaluates EVERY registered pattern to find one
            // that matches, not just the first) would silently pop an item
            // off THIS sequence for every probe POST too. Caught for real
            // via this exact test: the recheck GET came back exhausted
            // because a probe POST had consumed its response instead.
            'genieacs-nbi:7557/devices?*' => Http::sequence()
                ->push($initialQueryDevices, 200)
                ->push($recheckQueryDevices ?? $initialQueryDevices, 200),
        ]);
    }

    private function tenantDevice(array $attributes = []): CpeDevice
    {
        $tenant = Tenant::factory()->create();

        return CpeDevice::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    }

    public function test_device_with_fresh_last_inform_is_marked_online_without_any_active_probe(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-FRESH',
            'status' => CpeDeviceStatus::Offline,
        ]);

        $freshTimestamp = now()->subMinutes(2)->startOfSecond();
        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-FRESH', 'http://10.1.1.5:58000', $freshTimestamp->toIso8601String()),
        ]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['online']);
        $this->assertSame(0, $result['offline']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
        $this->assertTrue($device->fresh()->last_inform_at->equalTo($freshTimestamp));

        // No probe needed at all — never sent a connection_request task.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tasks'));
        Sleep::assertNeverSlept();
    }

    public function test_stale_device_that_responds_to_probe_within_the_wait_window_is_marked_online(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-STALE-OK',
            'status' => CpeDeviceStatus::Offline,
        ]);

        $staleTimestamp = now()->subHours(2)->startOfSecond();
        $freshAfterProbe = now()->addSeconds(30)->startOfSecond();

        $this->fakeGenieAcs(
            initialQueryDevices: [$this->genieAcsDevice('OUI-PC-STALE-OK', 'http://10.1.1.9:58000', $staleTimestamp->toIso8601String())],
            recheckQueryDevices: [$this->genieAcsDevice('OUI-PC-STALE-OK', 'http://10.1.1.9:58000', $freshAfterProbe->toIso8601String())],
        );

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['online']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
        $this->assertTrue($device->fresh()->last_inform_at->equalTo($freshAfterProbe));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'OUI-PC-STALE-OK/tasks') && str_contains($request->url(), 'connection_request'));
        // Real, measured worst-case confirmed delay was 60s — 90s covers it
        // with margin. See CpeDeviceStatusSyncService's own docblock.
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 90, times: 1);
    }

    public function test_stale_device_that_never_responds_within_the_wait_window_is_marked_offline(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-STALE-DEAD',
            'status' => CpeDeviceStatus::Online,
        ]);

        $staleTimestamp = now()->subHours(2)->startOfSecond();

        // Recheck shows the EXACT same stale timestamp — probe never landed.
        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-STALE-DEAD', 'http://10.1.1.9:58000', $staleTimestamp->toIso8601String()),
        ]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['online']);
        $this->assertSame(1, $result['offline']);
        $this->assertSame(CpeDeviceStatus::Offline, $device->fresh()->status);
    }

    /**
     * Real genieacs-nbi bug found this session: `getParameterValues` with
     * an EMPTY `parameterNames` array crashes the worker outright. The
     * probe must always carry a real, non-empty parameter path.
     */
    public function test_probe_never_sends_an_empty_parameter_names_array(): void
    {
        $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-STALE-PROBE',
            'tr069_root' => Tr069Root::InternetGatewayDevice,
        ]);

        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-STALE-PROBE', 'http://10.1.1.9:58000', now()->subHours(1)->toIso8601String()),
        ]);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return false;
            }

            $body = $request->data();

            return $body['name'] === 'getParameterValues'
                && $body['parameterNames'] !== []
                && $body['parameterNames'][0] === 'InternetGatewayDevice.DeviceInfo.SerialNumber';
        });
    }

    public function test_probe_uses_the_devices_own_tr069_root_for_the_parameter_path(): void
    {
        $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-STALE-DEVICEROOT',
            'tr069_root' => Tr069Root::Device,
        ]);

        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-STALE-DEVICEROOT', 'http://10.1.1.9:58000', now()->subHours(1)->toIso8601String()),
        ]);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return false;
            }

            return $request->data()['parameterNames'][0] === 'Device.DeviceInfo.SerialNumber';
        });
    }

    public function test_stale_device_with_no_connection_request_url_is_skipped_not_probed(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-NOURL',
            'status' => CpeDeviceStatus::Online,
        ]);

        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-NOURL', null, now()->subHours(1)->toIso8601String()),
        ]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tasks'));
        Sleep::assertNeverSlept();
    }

    public function test_status_changed_at_only_updates_on_a_real_transition_fresh_path(): void
    {
        $frozenChangeTime = now()->subDays(3)->startOfSecond();
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-STABLE',
            'status' => CpeDeviceStatus::Online,
            'status_changed_at' => $frozenChangeTime,
        ]);

        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-STABLE', 'http://10.1.1.5:58000', now()->subMinutes(1)->toIso8601String()),
        ]);

        app(CpeDeviceStatusSyncService::class)->syncAll();

        $device->refresh();
        $this->assertSame(CpeDeviceStatus::Online, $device->status);
        $this->assertTrue($device->status_changed_at->equalTo($frozenChangeTime));
    }

    public function test_status_changed_at_is_stamped_the_moment_status_flips_offline(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-FLIPPING',
            'status' => CpeDeviceStatus::Online,
            'status_changed_at' => now()->subDays(10),
        ]);

        $this->fakeGenieAcs([
            $this->genieAcsDevice('OUI-PC-FLIPPING', 'http://10.1.1.5:58000', now()->subHours(2)->toIso8601String()),
        ]);

        $before = now()->startOfSecond();
        app(CpeDeviceStatusSyncService::class)->syncAll();

        $device->refresh();
        $this->assertSame(CpeDeviceStatus::Offline, $device->status);
        $this->assertTrue($device->status_changed_at->greaterThanOrEqualTo($before));
    }

    public function test_pending_first_connect_devices_are_never_touched(): void
    {
        $device = $this->tenantDevice(['genieacs_device_id' => null, 'status' => CpeDeviceStatus::PendingFirstConnect]);
        $originalStatus = $device->status;

        $this->fakeGenieAcs([]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame($originalStatus, $device->fresh()->status);
        Sleep::assertNeverSlept();
    }

    public function test_missing_genieacs_query_result_skips_every_device_without_erroring(): void
    {
        $this->tenantDevice(['genieacs_device_id' => 'OUI-PC-ANY']);

        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response('Service Unavailable', 503),
        ]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        Sleep::assertNeverSlept();
    }

    public function test_a_device_missing_from_the_genieacs_response_entirely_is_skipped(): void
    {
        $device = $this->tenantDevice([
            'genieacs_device_id' => 'OUI-PC-GHOST',
            'status' => CpeDeviceStatus::Online,
        ]);

        // GenieACS's own device list simply doesn't contain this device_id
        // (anymore) at all.
        $this->fakeGenieAcs([]);

        $result = app(CpeDeviceStatusSyncService::class)->syncAll();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(CpeDeviceStatus::Online, $device->fresh()->status);
    }
}
