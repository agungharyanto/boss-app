<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\DeviceSyslogModal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceSyslogModalLivewireTest extends TestCase
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

    private function fakeService(array $rows = [], bool $throw = false): LibreNmsService
    {
        return new class($rows, $throw) extends LibreNmsService
        {
            public ?int $lastLimit = null;

            public ?int $lastLevel = -1;

            public function __construct(private readonly array $rows, private readonly bool $throw) {}

            public function getSyslog(int $deviceId, int $limit = 50, ?int $level = null): array
            {
                $this->lastLimit = $limit;
                $this->lastLevel = $level;

                if ($this->throw) {
                    throw new RuntimeException('LibreNMS unreachable');
                }

                return $this->rows;
            }
        };
    }

    public function test_open_loads_syslog_rows(): void
    {
        $rows = [
            ['timestamp' => '2026-08-25 05:56:53', 'host' => 'ro-hotspot.bajastu.id', 'program' => 'USER', 'level' => 4, 'msg' => '081285205789 authentication failed'],
        ];
        $this->app->instance(LibreNmsService::class, $this->fakeService($rows));

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->assertSet('showModal', true)
            ->assertSet('state', 'ok')
            ->assertSet('rows', $rows);
    }

    public function test_open_with_no_rows_shows_empty_state(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 1, 'ro-x86-kaliwungu.bajastu.id')
            ->assertSet('state', 'empty')
            ->assertSet('rows', []);
    }

    public function test_service_failure_sets_unavailable_state(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([], throw: true));

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->assertSet('state', 'unavailable')
            ->assertSet('rows', []);
    }

    public function test_change_level_passes_the_filter_through_to_the_service(): void
    {
        $service = $this->fakeService([]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->call('changeLevel', '4');

        $this->assertSame(4, $service->lastLevel);
    }

    public function test_change_level_to_empty_string_clears_the_filter(): void
    {
        $service = $this->fakeService([]);
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->call('changeLevel', '4')
            ->call('changeLevel', '');

        $this->assertNull($service->lastLevel);
    }

    public function test_change_limit_only_accepts_known_values(): void
    {
        $service = $this->fakeService([]);
        $this->app->instance(LibreNmsService::class, $service);

        $component = Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->call('changeLimit', 100);

        $this->assertSame(100, $service->lastLimit);

        $component->call('changeLimit', 999);
        $component->assertSet('limit', 100);
    }

    public function test_close_modal_hides_it(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin())
            ->test(DeviceSyslogModal::class)
            ->call('open', 8, 'ro-hotspot.bajastu.id')
            ->call('closeModal')
            ->assertSet('showModal', false);
    }

    public function test_mount_requires_monitoring_view_permission(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->nonMonitoring())
            ->test(DeviceSyslogModal::class)
            ->assertForbidden();
    }
}
