<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeDialupHistory;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\RadiusSessionHistoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CpeDialupHistoryLivewireTest extends TestCase
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

    private function fakeService(array $rows = []): RadiusSessionHistoryService
    {
        return new class($rows) extends RadiusSessionHistoryService
        {
            public ?Customer $lastCustomer = null;

            public ?int $lastLimit = null;

            public function __construct(private readonly array $rows) {}

            public function getHistoryForCustomer(Customer $customer, int $limit = 50): array
            {
                $this->lastCustomer = $customer;
                $this->lastLimit = $limit;

                return $this->rows;
            }
        };
    }

    public function test_device_with_no_radacct_rows_shows_empty_state(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(RadiusSessionHistoryService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id])
            ->assertSet('rows', [])
            ->assertSee(__('Belum ada riwayat sesi tercatat'));
    }

    public function test_device_with_real_sessions_renders_the_table(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $rows = [
            [
                'acct_id' => 467,
                'started_at' => Carbon::parse('2026-08-24 21:09:20'),
                'stopped_at' => Carbon::parse('2026-08-25 05:18:57'),
                'is_active' => false,
                'session_seconds' => 29377,
                'nas_ip' => '172.23.195.6',
                'upload_bytes' => 378714,
                'download_bytes' => 72,
                'terminate_cause' => 'NAS-Request',
            ],
            [
                'acct_id' => 468,
                'started_at' => Carbon::parse('2026-08-25 05:18:58'),
                'stopped_at' => null,
                'is_active' => true,
                'session_seconds' => 131,
                'nas_ip' => '172.23.195.6',
                'upload_bytes' => 0,
                'download_bytes' => 0,
                'terminate_cause' => null,
            ],
        ];
        $service = $this->fakeService($rows);
        $this->app->instance(RadiusSessionHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id])
            ->assertSee('467')
            ->assertSee('468')
            ->assertSee('NAS-Request')
            ->assertSee('369.84 KB')
            ->assertSee(__('Aktif'));

        $this->assertTrue($customer->is($service->lastCustomer));
    }

    public function test_a_cross_tenant_device_id_404s_before_the_policy_check_even_runs(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenantA->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenantA->id, 'customer_id' => $customer->id]);

        $outsider = $this->admin($tenantB);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($outsider)->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id]);
    }

    public function test_a_same_tenant_user_with_no_cpe_devices_permission_and_no_reseller_membership_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reseller_id' => null]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id])
            ->assertForbidden();
    }

    // ── v0.12.6 (revisi) — limit 10, kolom NAS dihapus, catatan Download ──

    public function test_the_service_is_called_with_a_limit_of_10_not_the_services_own_default_of_50(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $service = $this->fakeService([]);
        $this->app->instance(RadiusSessionHistoryService::class, $service);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id]);

        $this->assertSame(10, $service->lastLimit);
    }

    public function test_the_nas_column_is_no_longer_rendered(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $rows = [[
            'acct_id' => 467,
            'started_at' => Carbon::parse('2026-08-24 21:09:20'),
            'stopped_at' => Carbon::parse('2026-08-25 05:18:57'),
            'is_active' => false,
            'session_seconds' => 29377,
            'nas_ip' => '172.23.195.6',
            'upload_bytes' => 378714,
            'download_bytes' => 72,
            'terminate_cause' => 'NAS-Request',
        ]];
        $this->app->instance(RadiusSessionHistoryService::class, $this->fakeService($rows));

        $html = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id])
            ->assertSee('NAS-Request') // acctterminatecause TETAP ada, cuma kolom NAS-nya yang hilang
            ->html();

        // Bukan assertDontSee('NAS') murni -- "NAS-Request" di atas
        // genuinely mengandung substring "NAS", jadi itu akan false
        // positive. Cek header <th> kolom NAS spesifik tidak ada. (TIDAK
        // cek absennya string IP NAS di seluruh HTML -- Livewire's own
        // wire:snapshot menyerialisasi SELURUH $rows publik, termasuk
        // nas_ip, terlepas dari apakah kolomnya dirender ke tabel atau
        // tidak -- itu bukan sinyal soal tampilan visual.)
        $this->assertStringNotContainsString('>'.__('NAS').'<', $html);
    }

    public function test_download_caution_note_is_shown_in_the_header(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $this->app->instance(RadiusSessionHistoryService::class, $this->fakeService([]));

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDialupHistory::class, ['cpeDeviceId' => $device->id])
            ->assertSee(__('Kolom Download saat ini kurang mencerminkan pemakaian sebenarnya — keterbatasan sisi RouterOS/RADIUS, sedang diinvestigasi lebih lanjut.'));
    }
}
