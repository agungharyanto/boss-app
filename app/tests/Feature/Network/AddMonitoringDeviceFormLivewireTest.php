<?php

namespace Tests\Feature\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Livewire\Network\AddMonitoringDeviceForm;
use App\Livewire\Network\DeviceMonitoringList;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AddMonitoringDeviceFormLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function nocUser(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('noc');

        return $user;
    }

    private function fakeService(?array $addResult = null, ?string $throwMessage = null): LibreNmsService
    {
        return new class($addResult, $throwMessage) extends LibreNmsService
        {
            public function __construct(
                private readonly ?array $addResult,
                private readonly ?string $throwMessage,
            ) {}

            public function addDevice(
                string $hostname,
                string $snmpVersion,
                ?string $community,
                int $port,
                ?string $displayName = null,
            ): array {
                if ($this->throwMessage !== null) {
                    throw new LibreNmsDataUnavailableException($this->throwMessage);
                }

                return $this->addResult ?? ['device_id' => 1, 'hostname' => $hostname];
            }
        };
    }

    public function test_noc_role_can_open_and_submit_the_form(): void
    {
        $service = $this->fakeService(['device_id' => 42, 'hostname' => '10.1.1.5']);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->nocUser())
            ->test(AddMonitoringDeviceForm::class)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->set('hostname', '10.1.1.5')
            ->set('snmpVersion', 'v2c')
            ->set('community', 'public')
            ->set('port', 161)
            ->call('save')
            ->assertSet('errorMessage', null)
            ->assertSet('successMessage', 'Device "10.1.1.5" berhasil ditambahkan.');
    }

    public function test_a_user_without_monitoring_manage_cannot_mount_the_form(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // No role assigned at all — no monitoring.manage.

        Livewire::actingAs($user)
            ->test(AddMonitoringDeviceForm::class)
            ->assertForbidden();
    }

    public function test_hostname_and_community_are_required(): void
    {
        Livewire::actingAs($this->admin())
            ->test(AddMonitoringDeviceForm::class)
            ->call('openModal')
            ->set('hostname', '')
            ->set('community', '')
            ->call('save')
            ->assertHasErrors(['hostname' => 'required', 'community' => 'required']);
    }

    public function test_snmp_version_only_accepts_v1_or_v2c_never_v3(): void
    {
        // v3 explicitly out of scope this sprint — the dropdown only ever
        // offers v1/v2c, but the server-side rule is the real guard
        // against a forged wire:model payload.
        Livewire::actingAs($this->admin())
            ->test(AddMonitoringDeviceForm::class)
            ->call('openModal')
            ->set('hostname', '10.1.1.5')
            ->set('community', 'public')
            ->set('snmpVersion', 'v3')
            ->call('save')
            ->assertHasErrors(['snmpVersion' => 'in']);
    }

    public function test_a_real_librenms_error_message_is_shown_verbatim(): void
    {
        $service = $this->fakeService(throwMessage: 'Device 144.79.52.0 already exists');
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(AddMonitoringDeviceForm::class)
            ->call('openModal')
            ->set('hostname', '144.79.52.0')
            ->set('community', 'tokia121314')
            ->call('save')
            ->assertSet('errorMessage', 'Device 144.79.52.0 already exists')
            ->assertSet('successMessage', null);
    }

    public function test_successful_save_resets_the_form_and_dispatches_the_added_event(): void
    {
        $service = $this->fakeService(['device_id' => 7, 'hostname' => '10.1.1.5']);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(AddMonitoringDeviceForm::class)
            ->call('openModal')
            ->set('hostname', '10.1.1.5')
            ->set('community', 'public')
            ->set('displayName', 'Switch Gudang')
            ->call('save')
            ->assertDispatched('monitoring-device-added')
            ->assertSet('hostname', '')
            ->assertSet('community', '')
            ->assertSet('displayName', '')
            ->assertSet('port', 161)
            ->assertSet('snmpVersion', 'v2c');
    }

    public function test_device_monitoring_list_reloads_when_a_device_is_added(): void
    {
        $callCount = 0;
        $service = new class($callCount) extends LibreNmsService
        {
            public static int $calls = 0;

            public function __construct(private int &$callCount) {}

            public function listDevices(): array
            {
                self::$calls++;

                return self::$calls === 1
                    ? [['device_id' => 1, 'hostname' => 'a', 'sys_name' => null, 'status' => true, 'uptime' => 1]]
                    : [
                        ['device_id' => 1, 'hostname' => 'a', 'sys_name' => null, 'status' => true, 'uptime' => 1],
                        ['device_id' => 99, 'hostname' => '10.1.1.5', 'sys_name' => null, 'status' => true, 'uptime' => null],
                    ];
            }

            public function getCpuUsage(int $deviceId): array
            {
                return [];
            }

            public function getMemoryUsage(int $deviceId): array
            {
                return [];
            }

            public function getTemperature(int $deviceId): array
            {
                return [];
            }

            public function getAvailability(int $deviceId): array
            {
                return [];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceMonitoringList::class)
            ->assertCount('rows', 1)
            ->dispatch('monitoring-device-added')
            ->assertCount('rows', 2);
    }
}
