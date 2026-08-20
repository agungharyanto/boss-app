<?php

namespace Tests\Feature\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeDevice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\CpeActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CpeActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $attributes = []): CpeDevice
    {
        $tenant = Tenant::factory()->create();

        return CpeDevice::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ], $attributes));
    }

    private function actor(CpeDevice $device): User
    {
        return User::factory()->create(['tenant_id' => $device->tenant_id]);
    }

    public function test_reboot_writes_a_delivered_log_and_sends_a_reboot_task(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response([
                '_id' => 'genieacs-task-1', 'name' => 'reboot', 'timestamp' => now()->toIso8601String(),
            ], 202),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->reboot($device, $actor);

        $this->assertSame(CpeActionType::Reboot, $log->action_type);
        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame('genieacs-task-1', $log->genieacs_task_id);
        $this->assertSame($device->id, $log->cpe_device_id);
        $this->assertSame($device->tenant_id, $log->tenant_id);
        $this->assertSame($actor->id, $log->performed_by);
        $this->assertNotNull($log->completed_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/tasks')
            && $request['name'] === 'reboot'
            && str_contains($request->url(), 'connection_request'));

        $this->assertDatabaseHas('cpe_action_logs', ['id' => $log->id, 'status' => 'delivered']);
    }

    public function test_reboot_fails_gracefully_when_device_has_no_genieacs_id_yet(): void
    {
        $device = $this->device(['genieacs_device_id' => null]);
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->reboot($device, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
        $this->assertNull($log->genieacs_task_id);
    }

    public function test_reboot_fails_when_genieacs_enqueue_itself_errors(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->reboot($device, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    /**
     * Regression guard: a soft connection_request failure ("Device is
     * offline", real behavior confirmed manually during the v0.7.3
     * investigation — see CLAUDE.md) must NOT be treated as delivery
     * failure — the task is still genuinely enqueued.
     */
    public function test_reboot_is_still_delivered_when_connection_request_itself_fails(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response([
                '_id' => 'genieacs-task-2', 'name' => 'reboot',
            ], 202),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->reboot($device, $actor);

        $this->assertSame(CpeActionStatus::Delivered, $log->status);
    }

    public function test_set_wifi_credentials_requires_at_least_one_field(): void
    {
        $device = $this->device();
        $actor = $this->actor($device);

        $this->expectException(\InvalidArgumentException::class);

        app(CpeActionService::class)->setWifiCredentials($device, null, null, $actor);
    }

    /**
     * 2026-08-17: setWifiCredentials() no longer consults `cpe_parameter_maps`
     * at all for wifi_ssid/wifi_password — WLANConfiguration.{n}.SSID/
     * KeyPassphrase is standard TR-069, confirmed identical across every
     * vendor OUI in this fleet during the multi-SSID discovery work, so the
     * path is built directly from $ssidIndex. No findDeviceById() call
     * happens anymore either (no OUI/ProductClass lookup needed) — only the
     * /tasks fake is needed here now, unlike before this change.
     */
    public function test_set_ssid_defaults_to_index_1_when_not_given(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-3'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', null, $actor);

        $this->assertSame(CpeActionType::SetSsid, $log->action_type);
        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame(['ssid_index' => 1, 'new_ssid' => 'RumahBaru'], $log->parameters);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['name'] === 'setParameterValues'
                && $request['parameterValues'] === [
                    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'RumahBaru', 'xsd:string'],
                ];
        });
    }

    #[DataProvider('ssidIndexProvider')]
    public function test_set_ssid_targets_the_given_ssid_index(int $ssidIndex): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'TokenWifi', 'passwordbaru', $actor, $ssidIndex);

        $this->assertSame($ssidIndex, $log->parameters['ssid_index']);

        Http::assertSent(function ($request) use ($ssidIndex) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['parameterValues'] === [
                ["InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ssidIndex}.SSID", 'TokenWifi', 'xsd:string'],
                ["InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ssidIndex}.KeyPassphrase", 'passwordbaru', 'xsd:string'],
            ];
        });
    }

    /**
     * @return array<string, array{int}>
     */
    public static function ssidIndexProvider(): array
    {
        return [
            'index 1 (main SSID)' => [1],
            'index 4 (TOKEN WIFI, real fleet-observed index)' => [4],
            'index 5 (5GHz, real fleet-observed index)' => [5],
        ];
    }

    public function test_set_ssid_index_below_1_throws(): void
    {
        $device = $this->device();
        $actor = $this->actor($device);

        $this->expectException(\InvalidArgumentException::class);

        app(CpeActionService::class)->setWifiCredentials($device, 'x', null, $actor, 0);
    }

    public function test_set_password_only_never_stores_plaintext_password_in_the_log(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-4'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, null, 'SuperSecret123', $actor);

        $this->assertSame(CpeActionType::SetPassword, $log->action_type);
        $this->assertTrue($log->parameters['password_changed']);
        $this->assertSame(hash('sha256', 'SuperSecret123'), $log->parameters['new_password_fingerprint']);
        $this->assertArrayNotHasKey('new_password', $log->parameters);
        $this->assertStringNotContainsString('SuperSecret123', json_encode($log->parameters));
    }

    public function test_setting_both_ssid_and_password_sends_one_task_with_two_parameter_values(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-5'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', 'SuperSecret123', $actor);

        // Both changed together — action_type leans SetSsid per the
        // service's own documented tie-break, but `parameters` still
        // records both fields.
        $this->assertSame(CpeActionType::SetSsid, $log->action_type);
        $this->assertSame('RumahBaru', $log->parameters['new_ssid']);
        $this->assertTrue($log->parameters['password_changed']);

        $tasksSent = 0;
        Http::assertSent(function ($request) use (&$tasksSent) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            $tasksSent++;

            return count($request['parameterValues']) === 2;
        });
        $this->assertSame(1, $tasksSent);
    }

    public function test_set_wifi_credentials_fails_gracefully_when_device_has_no_genieacs_id_yet(): void
    {
        $device = $this->device(['genieacs_device_id' => null]);
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', null, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    public function test_set_wifi_credentials_fails_when_genieacs_enqueue_itself_errors(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['error' => 'bad request'], 400)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', null, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    public function test_set_ssid_enabled_true_writes_a_delivered_log_and_sends_the_enable_leaf(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-6'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setSsidEnabled($device, 4, true, $actor);

        $this->assertSame(CpeActionType::SetSsidEnabled, $log->action_type);
        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame(['ssid_index' => 4, 'enabled' => true], $log->parameters);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['parameterValues'] === [
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.Enable', true, 'xsd:boolean'],
            ];
        });
    }

    public function test_set_ssid_enabled_false_sends_the_disable_leaf(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-7'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setSsidEnabled($device, 1, false, $actor);

        $this->assertSame(['ssid_index' => 1, 'enabled' => false], $log->parameters);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            return $request['parameterValues'] === [
                ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable', false, 'xsd:boolean'],
            ];
        });
    }

    public function test_set_ssid_enabled_fails_gracefully_when_genieacs_enqueue_errors(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['error' => 'bad request'], 400)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setSsidEnabled($device, 1, false, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    public function test_set_ssid_enabled_index_below_1_throws(): void
    {
        $device = $this->device();
        $actor = $this->actor($device);

        $this->expectException(\InvalidArgumentException::class);

        app(CpeActionService::class)->setSsidEnabled($device, 0, true, $actor);
    }

    public function test_sync_now_sends_wan_and_lan_refresh_object_tasks_and_is_delivered(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-sync'], 202)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->syncNow($device, $actor);

        $this->assertSame(CpeActionType::SyncNow, $log->action_type);
        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame(['wan' => 'genieacs-task-sync', 'lan' => 'genieacs-task-sync'], $log->parameters['task_ids']);
        $this->assertSame('genieacs-task-sync,genieacs-task-sync', $log->genieacs_task_id);

        $tasksSent = 0;
        Http::assertSent(function ($request) use (&$tasksSent) {
            if (! str_contains($request->url(), '/tasks')) {
                return true;
            }

            $tasksSent++;

            return $request['name'] === 'refreshObject'
                && in_array($request['objectName'], ['InternetGatewayDevice.WANDevice', 'InternetGatewayDevice.LANDevice'], true);
        });
        $this->assertSame(2, $tasksSent);
    }

    public function test_sync_now_is_still_delivered_when_only_one_of_the_two_tasks_enqueues(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::sequence()
                ->push(['_id' => 'genieacs-task-wan-ok'], 202)
                ->push(['error' => 'bad request'], 400),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->syncNow($device, $actor);

        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame(['wan' => 'genieacs-task-wan-ok'], $log->parameters['task_ids']);
        $this->assertArrayHasKey('lan', $log->parameters['errors']);
    }

    public function test_sync_now_fails_gracefully_when_both_tasks_fail_to_enqueue(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['error' => 'bad request'], 400)]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->syncNow($device, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }

    public function test_sync_now_fails_gracefully_when_device_has_no_genieacs_id_yet(): void
    {
        $device = $this->device(['genieacs_device_id' => null]);
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->syncNow($device, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_reason);
    }
}
