<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeUsageHistoryGraph;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\CpeUsageHistoryService;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.6 Bagian 2 — "Grafik Pemakaian". Dua state (bukan tiga seperti RX
 * Power) — 'no_data' (customer TIDAK PUNYA satu pun row radacct SAMA
 * SEKALI) vs 'ok' (render chart, hari tanpa sesi tetap tampil 0). Pola
 * fake service via $this->app->instance() PERSIS CpeDialupHistoryLivewireTest
 * (v0.8.4) — anonymous class extends service, override method publik.
 *
 * v0.12.6 (revisi) — modal "Riwayat" (App\Enums\CpeUsageHistoryRange, 4
 * tab: 30 Hari/3 Bulan/6 Bulan/1 Tahun + Custom), pola test PERSIS
 * CpeSignalHistoryGraphLivewireTest's own modal coverage.
 */
class CpeUsageHistoryGraphLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function fakeService(
        bool $hasAnyRecordedUsage,
        array $series = [],
        ?Closure $dailyUsageResolver = null,
        ?Closure $customUsageResolver = null,
    ): CpeUsageHistoryService {
        return new class($hasAnyRecordedUsage, $series, $dailyUsageResolver, $customUsageResolver) extends CpeUsageHistoryService
        {
            public ?Customer $lastCustomer = null;

            /** @var array<int, int> */
            public array $dailyUsageDaysCalls = [];

            public function __construct(
                private readonly bool $hasAnyRecordedUsage,
                private readonly array $series,
                private readonly ?Closure $dailyUsageResolver,
                private readonly ?Closure $customUsageResolver,
            ) {}

            public function hasAnyRecordedUsage(Customer $customer): bool
            {
                $this->lastCustomer = $customer;

                return $this->hasAnyRecordedUsage;
            }

            public function dailyUsageForCustomer(Customer $customer, int $days = 30): array
            {
                $this->dailyUsageDaysCalls[] = $days;

                return $this->dailyUsageResolver !== null ? ($this->dailyUsageResolver)($days) : $this->series;
            }

            public function customDailyUsageForCustomer(Customer $customer, Carbon $from, Carbon $to): array
            {
                return $this->customUsageResolver !== null ? ($this->customUsageResolver)($from, $to) : $this->series;
            }
        };
    }

    public function test_customer_with_no_radacct_rows_at_all_shows_no_data_state(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(false));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'no_data')
            ->assertSet('series', [])
            ->assertSee(__('Belum ada data pemakaian — akun ini belum aktif tercatat lewat FreeRADIUS boss-app.'));
    }

    public function test_customer_with_recorded_usage_shows_ok_state_and_dispatches_the_series(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $series = [
            ['date' => '2026-09-01', 'upload_mb' => 0.0, 'download_mb' => 0.0],
            ['date' => '2026-09-02', 'upload_mb' => 1.5, 'download_mb' => 3.2],
        ];
        $service = $this->fakeService(true, $series);
        $this->app->instance(CpeUsageHistoryService::class, $service);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'ok')
            ->assertSet('series', $series)
            ->assertDispatched('usage-history-series-updated');

        $this->assertTrue($customer->is($service->lastCustomer));
        $component->assertDontSee(__('Belum ada data pemakaian — akun ini belum aktif tercatat lewat FreeRADIUS boss-app.'));
    }

    public function test_a_cross_tenant_device_id_404s_before_the_policy_check_even_runs(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenantA->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenantA->id, 'customer_id' => $customer->id]);

        $outsider = $this->admin($tenantB);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($outsider)->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id]);
    }

    public function test_a_same_tenant_user_with_no_cpe_devices_permission_and_no_reseller_membership_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertForbidden();
    }

    // ── v0.12.6 (revisi) — Bagian 3: catatan Download ──

    public function test_download_caution_note_is_shown_regardless_of_state(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $deviceOk = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $deviceOk->id])
            ->assertSee(__('Grafik Download saat ini kurang mencerminkan pemakaian sebenarnya — keterbatasan sisi RouterOS/RADIUS, sedang diinvestigasi lebih lanjut.'));

        $customerNoData = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $deviceNoData = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerNoData->id]);
        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(false));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $deviceNoData->id])
            ->assertSee(__('Grafik Download saat ini kurang mencerminkan pemakaian sebenarnya — keterbatasan sisi RouterOS/RADIUS, sedang diinvestigasi lebih lanjut.'));
    }

    // ── v0.12.6 (revisi) — Bagian 2: modal "Riwayat" ──

    public function test_history_button_is_visible_only_when_state_is_ok(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSee(__('Riwayat'));
    }

    public function test_history_button_is_hidden_when_state_is_no_data(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(false));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertDontSee(__('Riwayat'));
    }

    public function test_main_graph_never_shows_a_range_selector(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertDontSee('role="tab"', false)
            ->assertSee(__('Grafik Pemakaian'))
            ->assertSee(__('Riwayat'));
    }

    public function test_modal_is_closed_by_default_and_opens_on_demand(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('showHistoryModal', false)
            ->call('openHistoryModal')
            ->assertSet('showHistoryModal', true)
            ->assertSet('modalRange', '30_days')
            ->call('closeHistoryModal')
            ->assertSet('showHistoryModal', false);
    }

    public function test_opening_the_modal_loads_the_default_30_days_range(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $series = [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ];
        $service = $this->fakeService(true, $series);
        $this->app->instance(CpeUsageHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->assertSet('modalState', 'ok')
            ->assertSet('modalSeries', $series);

        // openHistoryModal() -> loadModalSeries() pakai windowDays() dari
        // rentang default (30 Hari) -- dipanggil TERPISAH dari mount()'s
        // sendiri (yang selalu fixed 30).
        $this->assertContains(30, $service->dailyUsageDaysCalls);
    }

    public function test_changing_the_modal_range_reloads_only_the_modal_series(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $resolver = fn (int $days) => [['date' => '2026-09-01', 'upload_mb' => (float) $days, 'download_mb' => 0.0]];
        $service = $this->fakeService(true, [], $resolver);
        $this->app->instance(CpeUsageHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('series.0.upload_mb', 30.0) // mount() fixed 30 hari
            ->call('openHistoryModal')
            ->assertSet('modalSeries.0.upload_mb', 30.0)
            ->call('changeModalRange', '365_days')
            ->assertSet('modalRange', '365_days')
            ->assertSet('modalState', 'ok')
            ->assertSet('modalSeries.0.upload_mb', 365.0)
            // Grafik utama TIDAK ikut berubah -- fixed 30 hari selamanya.
            ->assertSet('series.0.upload_mb', 30.0);
    }

    public function test_changing_the_modal_range_dispatches_the_modal_specific_event(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('changeModalRange', '90_days')
            ->assertDispatched('usage-history-modal-series-updated')
            ->assertNotDispatched('usage-history-series-updated');
    }

    public function test_an_invalid_modal_range_value_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        $this->expectException(\ValueError::class);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('changeModalRange', 'not-a-real-range');
    }

    public function test_modal_shows_no_data_message_when_customer_has_no_radacct_rows_at_all(): void
    {
        // hasAnyRecordedUsage() dicek TANPA batas tanggal -- kalau false di
        // grafik utama, modal untuk rentang berapa pun juga pasti kosong
        // (lihat komponen ini sendiri untuk alasan tombol Riwayat disembunyikan
        // di kasus ini -- test ini membuktikan openHistoryModal() TETAP aman
        // dipanggil langsung, bukan cuma "tombolnya disembunyikan").
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(false));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->assertSet('modalState', 'no_data')
            ->assertSet('modalSeries', []);
    }

    public function test_selecting_the_custom_tab_shows_inputs_without_loading_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $service = $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]);
        $this->app->instance(CpeUsageHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            ->assertSee(__('Dari'))
            ->assertSee(__('Sampai'));

        // selectCustomRangeTab() sendiri tidak query apa pun tambahan --
        // 2 panggilan tercatat cuma dari mount() (grafik utama, fixed 30
        // hari) + openHistoryModal() sebelumnya, bukan dari tab Custom.
        $this->assertCount(2, $service->dailyUsageDaysCalls);
    }

    public function test_apply_custom_range_loads_the_matching_series(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $customSeries = [
            ['date' => '2026-06-01', 'upload_mb' => 9.0, 'download_mb' => 4.0],
        ];
        $resolver = function (Carbon $from, Carbon $to) use ($customSeries) {
            return $customSeries;
        };
        $service = $this->fakeService(true, [], null, $resolver);
        $this->app->instance(CpeUsageHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', '2026-06-01')
            ->set('customTo', '2026-06-10')
            ->call('applyCustomRange')
            ->assertSet('modalState', 'ok')
            ->assertSet('modalSeries', $customSeries);
    }

    public function test_custom_range_with_to_before_from_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', '2026-06-10')
            ->set('customTo', '2026-06-01')
            ->call('applyCustomRange')
            ->assertSet('customRangeError', '"Sampai" tidak boleh sebelum "Dari".');
    }

    public function test_custom_range_over_two_years_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(CpeUsageHistoryService::class, $this->fakeService(true, [
            ['date' => '2026-09-01', 'upload_mb' => 1.0, 'download_mb' => 2.0],
        ]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeUsageHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', '2020-01-01')
            ->set('customTo', '2026-01-01')
            ->call('applyCustomRange')
            ->assertSet('customRangeError', 'Rentang maksimum adalah 2 tahun.');
    }
}
