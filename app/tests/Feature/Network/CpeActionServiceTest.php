<?php

namespace Tests\Feature\Network;

use App\Enums\CpeActionStatus;
use App\Enums\CpeActionType;
use App\Models\CpeDevice;
use App\Models\CpeParameterMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\CpeActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeZteDeviceIdentity(): array
    {
        return [
            '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
            '_deviceId' => [
                '_Manufacturer' => 'ZICG',
                '_OUI' => 'F86CE1',
                '_ProductClass' => 'F663NV3a',
                '_SerialNumber' => 'ZICG296C2E7B',
            ],
        ];
    }

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
        // Real GenieACS behavior (confirmed manually during the v0.7.3
        // investigation): a 202 with reason phrase "Device is offline" and
        // a real task _id in the body — connection_request failed, but the
        // task itself is genuinely enqueued. sendTask() only looks at the
        // numeric status + body, never the reason phrase text, so a plain
        // 202 response is sufficient to exercise this path.
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

    public function test_set_ssid_only_resolves_path_and_sends_a_single_parameter_value(): void
    {
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_ssid',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);

        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-3'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([$this->fakeZteDeviceIdentity()], 200),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', null, $actor);

        $this->assertSame(CpeActionType::SetSsid, $log->action_type);
        $this->assertSame(CpeActionStatus::Delivered, $log->status);
        $this->assertSame(['new_ssid' => 'RumahBaru'], $log->parameters);

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

    public function test_set_password_only_never_stores_plaintext_password_in_the_log(): void
    {
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_password',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
        ]);

        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-4'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([$this->fakeZteDeviceIdentity()], 200),
        ]);

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
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_ssid',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        ]);
        CpeParameterMap::factory()->create([
            'oui' => 'F86CE1',
            'product_class' => 'F663NV3a',
            'parameter_key' => 'wifi_password',
            'parameter_path' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
        ]);

        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-5'], 202),
            'genieacs-nbi:7557/devices*' => Http::response([$this->fakeZteDeviceIdentity()], 200),
        ]);

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

    public function test_set_wifi_credentials_fails_gracefully_when_parameter_mapping_is_missing(): void
    {
        // No CpeParameterMap row created at all for this OUI/product_class.
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([$this->fakeZteDeviceIdentity()], 200),
        ]);

        $device = $this->device();
        $actor = $this->actor($device);

        $log = app(CpeActionService::class)->setWifiCredentials($device, 'RumahBaru', null, $actor);

        $this->assertSame(CpeActionStatus::Failed, $log->status);
        $this->assertStringContainsString('wifi_ssid', $log->failed_reason);
    }
}
