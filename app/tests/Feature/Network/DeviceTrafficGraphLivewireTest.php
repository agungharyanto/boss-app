<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\DeviceTrafficGraph;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceTrafficGraphLivewireTest extends TestCase
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

    private function fakeService(array $ports = [], array $series = [], bool $throw = false): LibreNmsService
    {
        return new class($ports, $series, $throw) extends LibreNmsService
        {
            public function __construct(
                private readonly array $ports,
                private readonly array $series,
                private readonly bool $throw,
            ) {}

            public function listPorts(int $deviceId): array
            {
                return $this->ports;
            }

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
            {
                if ($this->throw) {
                    throw new RuntimeException('LibreNMS unreachable');
                }

                return $this->series;
            }
        };
    }

    public function test_bare_mount_shows_empty_state_until_a_device_is_selected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class)
            ->assertSet('state', 'empty');
    }

    public function test_mount_with_device_id_auto_selects_the_up_interface_and_loads_series(): void
    {
        $service = $this->fakeService(
            ports: [
                ['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'down'],
                ['port_id' => 2, 'if_name' => 'ether2', 'if_oper_status' => 'up'],
            ],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 5.0, 'out_bytes_per_second' => 10.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('selectedIfName', 'ether2')
            ->assertSet('state', 'ok')
            ->assertSet('series.0.in_bytes_per_second', 5.0);
    }

    public function test_device_selected_event_from_sibling_component_loads_the_graph(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class)
            ->assertSet('state', 'empty')
            ->dispatch('device-selected', deviceId: 1)
            ->assertSet('deviceId', 1)
            ->assertSet('state', 'ok');
    }

    public function test_device_selected_with_null_resets_to_empty_state(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('state', 'ok')
            ->dispatch('device-selected', deviceId: null)
            ->assertSet('state', 'empty')
            ->assertSet('deviceId', null);
    }

    public function test_rrdtool_failure_degrades_to_unavailable_state_without_throwing(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            throw: true,
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->assertSet('state', 'unavailable');
    }

    public function test_changing_selected_interface_reloads_series(): void
    {
        $service = $this->fakeService(
            ports: [
                ['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up'],
                ['port_id' => 2, 'if_name' => 'ether2', 'if_oper_status' => 'up'],
            ],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 9.0, 'out_bytes_per_second' => 9.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->set('selectedIfName', 'ether2')
            ->assertSet('state', 'ok')
            ->assertSet('series.0.in_bytes_per_second', 9.0);
    }

    // v0.8.4 — "Riwayat" modal (same internal-modal pattern as
    // CpeSignalHistoryGraph, reused rather than a new component)

    public function test_open_history_modal_loads_the_default_day_range(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->assertSet('showHistoryModal', true)
            ->assertSet('modalRange', 'day')
            ->assertSet('modalState', 'ok')
            ->assertSet('modalSeries.0.in_bytes_per_second', 1.0);
    }

    public function test_changing_modal_range_reloads_with_a_wider_window(): void
    {
        $service = new class(['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']) extends LibreNmsService
        {
            public array $requestedRangeSeconds = [];

            public function __construct(private readonly array $port) {}

            public function listPorts(int $deviceId): array
            {
                return [$this->port];
            }

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
            {
                $this->requestedRangeSeconds[] = $rangeSeconds;

                return [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('changeModalRange', 'week')
            ->assertSet('modalRange', 'week');

        // Day (24h = 86400s) for the initial modal open, Week (7d = 604800s)
        // after switching — proves the tab switch actually widens the
        // rrdtool xport range, not just the UI label.
        $this->assertContains(86400, $service->requestedRangeSeconds);
        $this->assertContains(604800, $service->requestedRangeSeconds);
    }

    public function test_modal_degrades_to_unavailable_without_throwing(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            throw: true,
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->assertSet('modalState', 'unavailable')
            ->assertSet('modalSeries', []);
    }

    public function test_close_history_modal_hides_it(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->assertSet('showHistoryModal', true)
            ->call('closeHistoryModal')
            ->assertSet('showHistoryModal', false);
    }

    // v0.8.3 — Custom Date Range 6th tab (CLAUDE.md).

    public function test_selecting_the_custom_tab_shows_inputs_without_loading_anything(): void
    {
        $service = new class(['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']) extends LibreNmsService
        {
            public int $traffic_calls = 0;

            public function __construct(private readonly array $port) {}

            public function listPorts(int $deviceId): array
            {
                return [$this->port];
            }

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
            {
                $this->traffic_calls++;

                return [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            ->assertSet('customRangeError', null);

        // 2 = mount()'s own default loadSeries() call for the main graph,
        // plus openHistoryModal()'s own default-Day load for the modal.
        // Selecting the Custom tab must not trigger a THIRD call on its own.
        $this->assertSame(2, $service->traffic_calls);
    }

    public function test_apply_custom_range_loads_the_matching_series_with_the_correct_end_at(): void
    {
        $service = new class(['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']) extends LibreNmsService
        {
            public ?int $requestedRangeSeconds = null;

            public ?Carbon $requestedEndAt = null;

            public function __construct(private readonly array $port) {}

            public function listPorts(int $deviceId): array
            {
                return [$this->port];
            }

            public function getTrafficHistory(int $deviceId, string $ifName, int $rangeSeconds = 1800, ?Carbon $endAt = null): array
            {
                $this->requestedRangeSeconds = $rangeSeconds;
                $this->requestedEndAt = $endAt;

                return [['timestamp' => 1000, 'in_bytes_per_second' => 3.0, 'out_bytes_per_second' => 4.0]];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $from = now()->subMonths(2)->startOfDay();
        $to = $from->copy()->addDays(3);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', $from->toDateString())
            ->set('customTo', $to->toDateString())
            ->call('applyCustomRange')
            ->assertSet('modalState', 'ok')
            ->assertSet('modalSeries.0.in_bytes_per_second', 3.0);

        $this->assertNotNull($service->requestedEndAt);
        $this->assertSame($to->copy()->endOfDay()->getTimestamp(), $service->requestedEndAt->getTimestamp());

        // Deliberately computed via raw Unix-timestamp subtraction, NOT
        // Carbon::diffInSeconds() — see DeviceHistoryModalLivewireTest's
        // equivalent assertion for why: an earlier version of this exact
        // assertion used diffInSeconds() the SAME (buggy) way the
        // production code did, which masked a real negative-rangeSeconds
        // bug (found via a genuine reported 500 — see CLAUDE.md) instead
        // of catching it.
        $expectedRangeSeconds = $to->copy()->endOfDay()->getTimestamp() - $from->copy()->startOfDay()->getTimestamp();
        $this->assertGreaterThan(0, $service->requestedRangeSeconds);
        $this->assertSame($expectedRangeSeconds, $service->requestedRangeSeconds);
    }

    public function test_custom_range_with_to_before_from_is_rejected(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->toDateString())
            ->set('customTo', now()->subDay()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('customRangeError', '"Sampai" tidak boleh sebelum "Dari".');
    }

    public function test_custom_range_over_two_years_is_rejected(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->subYears(3)->toDateString())
            ->set('customTo', now()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('customRangeError', 'Rentang maksimum adalah 2 tahun.');
    }

    public function test_custom_range_with_empty_dates_is_rejected(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->call('applyCustomRange')
            ->assertSet('customRangeError', 'Tanggal "Dari" dan "Sampai" wajib diisi.');
    }

    public function test_choosing_a_preset_tab_after_custom_exits_custom_mode(): void
    {
        $service = $this->fakeService(
            ports: [['port_id' => 1, 'if_name' => 'ether1', 'if_oper_status' => 'up']],
            series: [['timestamp' => 1000, 'in_bytes_per_second' => 1.0, 'out_bytes_per_second' => 2.0]],
        );
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceTrafficGraph::class, ['deviceId' => 1])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            ->call('changeModalRange', 'week')
            ->assertSet('customRangeMode', false)
            ->assertSet('modalRange', 'week');
    }
}
