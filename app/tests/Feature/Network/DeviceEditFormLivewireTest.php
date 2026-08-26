<?php

namespace Tests\Feature\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Livewire\Network\DeviceEditForm;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceEditFormLivewireTest extends TestCase
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
        $user->assignRole('superadmin');

        return $user;
    }

    private function nonMonitoring(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

        return $user;
    }

    private function fakeService(?array $editable = null, ?string $updateThrowMessage = null): LibreNmsService
    {
        return new class($editable, $updateThrowMessage) extends LibreNmsService
        {
            public ?array $updatedFields = null;

            public function __construct(
                private readonly ?array $editable,
                private readonly ?string $updateThrowMessage,
            ) {}

            public function getEditableDevice(int $deviceId): ?array
            {
                return $this->editable;
            }

            public function updateDevice(int $deviceId, array $fields): void
            {
                if ($this->updateThrowMessage !== null) {
                    throw new LibreNmsDataUnavailableException($this->updateThrowMessage);
                }

                $this->updatedFields = $fields;
            }
        };
    }

    public function test_open_prefills_the_form_from_the_current_device(): void
    {
        $service = $this->fakeService([
            'device_id' => 2,
            'hostname' => '10.168.100.34',
            'display_template' => 'C300',
            'community' => 'tokia121314',
            'port' => 161,
            'snmpver' => 'v2c',
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceEditForm::class)
            ->call('open', 2)
            ->assertSet('showModal', true)
            ->assertSet('hostname', '10.168.100.34')
            ->assertSet('displayName', 'C300')
            ->assertSet('community', 'tokia121314')
            ->assertSet('port', 161)
            ->assertSet('snmpVersion', 'v2c');
    }

    public function test_save_sends_the_whitelisted_fields(): void
    {
        $service = $this->fakeService([
            'device_id' => 2,
            'hostname' => '10.168.100.34',
            'display_template' => null,
            'community' => 'old',
            'port' => 161,
            'snmpver' => 'v2c',
        ]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceEditForm::class)
            ->call('open', 2)
            ->set('displayName', 'C300 Kaliwungu')
            ->set('community', 'newcommunity')
            ->set('port', 2161)
            ->set('snmpVersion', 'v2c')
            ->call('save')
            ->assertSet('successMessage', 'Device berhasil diperbarui.')
            ->assertDispatched('monitoring-device-updated');

        $this->assertSame([
            'display_template' => 'C300 Kaliwungu',
            'community' => 'newcommunity',
            'port' => 2161,
            'snmpver' => 'v2c',
        ], $service->updatedFields);
    }

    public function test_save_shows_librenms_own_error_message_on_failure(): void
    {
        $service = $this->fakeService(
            ['device_id' => 2, 'hostname' => 'x', 'display_template' => null, 'community' => 'c', 'port' => 161, 'snmpver' => 'v2c'],
            updateThrowMessage: 'Device does not exist'
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceEditForm::class)
            ->call('open', 2)
            ->call('save')
            ->assertSet('errorMessage', 'Device does not exist');
    }

    public function test_community_is_required(): void
    {
        $service = $this->fakeService(
            ['device_id' => 2, 'hostname' => 'x', 'display_template' => null, 'community' => 'c', 'port' => 161, 'snmpver' => 'v2c']
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceEditForm::class)
            ->call('open', 2)
            ->set('community', '')
            ->call('save')
            ->assertHasErrors(['community']);
    }

    public function test_guest_without_manage_permission_cannot_mount(): void
    {
        Livewire::actingAs($this->nonMonitoring())
            ->test(DeviceEditForm::class)
            ->assertForbidden();
    }
}
