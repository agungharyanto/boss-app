<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeUsageHistoryGraph;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\CpeUsageHistoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.6 Bagian 2 — "Grafik Pemakaian". Dua state (bukan tiga seperti RX
 * Power) — 'no_data' (customer TIDAK PUNYA satu pun row radacct SAMA
 * SEKALI) vs 'ok' (render chart, hari tanpa sesi tetap tampil 0). Pola
 * fake service via $this->app->instance() PERSIS CpeDialupHistoryLivewireTest
 * (v0.8.4) — anonymous class extends service, override method publik.
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

    private function fakeService(bool $hasAnyRecordedUsage, array $series = []): CpeUsageHistoryService
    {
        return new class($hasAnyRecordedUsage, $series) extends CpeUsageHistoryService
        {
            public ?Customer $lastCustomer = null;

            public function __construct(private readonly bool $hasAnyRecordedUsage, private readonly array $series) {}

            public function hasAnyRecordedUsage(Customer $customer): bool
            {
                $this->lastCustomer = $customer;

                return $this->hasAnyRecordedUsage;
            }

            public function dailyUsageForCustomer(Customer $customer, int $days = 30): array
            {
                return $this->series;
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
}
