<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\DeviceHistoryModal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\LibreNmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceHistoryModalLivewireTest extends TestCase
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

    private function fakeService(array $series = [], bool $throw = false): LibreNmsService
    {
        return new class($series, $throw) extends LibreNmsService
        {
            public function __construct(private readonly array $series, private readonly bool $throw) {}

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                if ($this->throw) {
                    throw new RuntimeException('LibreNMS unreachable');
                }

                return $this->series;
            }
        };
    }

    public function test_open_loads_cpu_history_by_default(): void
    {
        $series = [['sensor_id' => 49, 'label' => 'PRWH', 'points' => [['timestamp' => 1000, 'value' => 5.0]]]];
        $this->app->instance(LibreNmsService::class, $this->fakeService($series));

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->assertSet('showModal', true)
            ->assertSet('metric', 'cpu')
            ->assertSet('state', 'ok')
            ->assertSet('series', $series);
    }

    public function test_no_sensor_state_when_metric_history_is_empty(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 3, 'olt-cileg')
            ->assertSet('state', 'no_sensor');
    }

    public function test_unavailable_state_on_service_failure(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([], throw: true));

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->assertSet('state', 'unavailable');
    }

    public function test_changing_metric_reloads_history(): void
    {
        $service = new class extends LibreNmsService
        {
            public array $calledMetrics = [];

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                $this->calledMetrics[] = $metric;

                return [];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->call('changeMetric', 'memory')
            ->assertSet('metric', 'memory');

        $this->assertSame(['cpu', 'memory'], $service->calledMetrics);
    }

    public function test_an_unknown_metric_is_silently_ignored(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->call('changeMetric', 'not_a_real_metric')
            ->assertSet('metric', 'cpu');
    }

    public function test_guest_without_permission_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

        Livewire::actingAs($user)
            ->test(DeviceHistoryModal::class)
            ->assertForbidden();
    }

    // v0.8.3 — Custom Date Range 6th tab (CLAUDE.md).

    public function test_apply_custom_range_passes_the_correct_end_at_and_range_seconds(): void
    {
        $service = new class extends LibreNmsService
        {
            public ?int $requestedRangeSeconds = null;

            public ?Carbon $requestedEndAt = null;

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                $this->requestedRangeSeconds = $rangeSeconds;
                $this->requestedEndAt = $endAt;

                return [['sensor_id' => 1, 'label' => 'x', 'points' => []]];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $from = now()->subMonths(3)->startOfDay();
        $to = $from->copy()->addDays(4);

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->call('selectCustomRangeTab')
            ->set('customFrom', $from->toDateString())
            ->set('customTo', $to->toDateString())
            ->call('applyCustomRange')
            ->assertSet('state', 'ok');

        // customTo is normalized to endOfDay() by the shared validation
        // trait — assert the endAt actually reached the service is that
        // exact boundary, not just "some Carbon instance".
        $this->assertNotNull($service->requestedEndAt);
        $this->assertSame($to->copy()->endOfDay()->getTimestamp(), $service->requestedEndAt->getTimestamp());

        // Deliberately computed via raw Unix-timestamp subtraction, NOT
        // Carbon::diffInSeconds() — a real bug (found via a genuine
        // reported 500, see CLAUDE.md) had this exact assertion computed
        // via diffInSeconds() the SAME way the (buggy) production code
        // did, which meant the expected and actual values matched each
        // other while BOTH were silently negative, masking the bug
        // entirely. This independent computation is what actually catches
        // it: rangeSeconds for a "Dari" earlier than "Sampai" must be
        // POSITIVE and must equal the exact real span in seconds.
        $expectedRangeSeconds = $to->copy()->endOfDay()->getTimestamp() - $from->copy()->startOfDay()->getTimestamp();
        $this->assertGreaterThan(0, $service->requestedRangeSeconds);
        $this->assertSame($expectedRangeSeconds, $service->requestedRangeSeconds);
    }

    public function test_custom_range_validation_error_is_set_and_nothing_is_queried(): void
    {
        $service = new class extends LibreNmsService
        {
            public int $calls = 0;

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        $component = Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu');

        // open()'s own default loadHistory() call already invoked the
        // service once (the "cpu" metric) — capture that baseline before
        // asserting the invalid custom range triggers no ADDITIONAL call.
        $callsBeforeInvalidApply = $service->calls;

        $component
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->toDateString())
            ->set('customTo', now()->subDay()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('customRangeError', '"Sampai" tidak boleh sebelum "Dari".');

        $this->assertSame($callsBeforeInvalidApply, $service->calls);
    }

    public function test_changing_metric_while_in_custom_mode_reapplies_the_same_custom_range(): void
    {
        $service = new class extends LibreNmsService
        {
            public array $calledMetrics = [];

            public function getMetricHistory(int $deviceId, string $metric, int $rangeSeconds, ?Carbon $endAt = null): array
            {
                $this->calledMetrics[] = [$metric, $endAt !== null];

                return [];
            }
        };
        $this->app->instance(LibreNmsService::class, $service);

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->subMonths(2)->toDateString())
            ->set('customTo', now()->subMonths(1)->toDateString())
            ->call('applyCustomRange')
            ->call('changeMetric', 'memory')
            ->assertSet('customRangeMode', true)
            ->assertSet('metric', 'memory');

        // "cpu" from open()'s own default loadHistory() call, then the
        // custom-range "cpu" call from applyCustomRange(), then "memory"
        // — the last two both carrying a real $endAt (custom mode), the
        // first one not.
        $this->assertSame(
            [['cpu', false], ['cpu', true], ['memory', true]],
            $service->calledMetrics
        );
    }

    public function test_choosing_a_preset_range_after_custom_exits_custom_mode(): void
    {
        $this->app->instance(LibreNmsService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin())
            ->test(DeviceHistoryModal::class)
            ->call('open', 2, 'c300.kaliwungu')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            ->call('changeRange', 'week')
            ->assertSet('customRangeMode', false)
            ->assertSet('range', 'week');
    }
}
