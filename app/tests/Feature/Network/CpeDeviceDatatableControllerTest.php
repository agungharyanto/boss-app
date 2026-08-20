<?php

namespace Tests\Feature\Network;

use App\Enums\CpeDeviceStatus;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/internal/cpe-devices/datatable (v0.7.6-follow-up) — real
 * yajra server-side sort/search/pagination, not a client-side dataset. Uses
 * the DataTables client's own standard query-string shape (draw/start/
 * length/search[value]/order[0][column]/columns[i][name]) rather than
 * hand-rolled params, so this actually exercises the same request format
 * jquery.dataTables.min.js sends.
 */
class CpeDeviceDatatableControllerTest extends TestCase
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
        $admin->assignRole('super_admin');

        return $admin;
    }

    /**
     * @param  array<int, array{data: string, name?: string, orderable?: bool}>  $columns
     * @return array<string, mixed>
     */
    private function dtColumns(array $columns): array
    {
        return collect($columns)->map(fn (array $c, int $i) => [
            'data' => $c['data'],
            'name' => $c['name'] ?? $c['data'],
            'searchable' => 'true',
            'orderable' => ($c['orderable'] ?? true) ? 'true' : 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ])->values()->all();
    }

    private function baseColumns(): array
    {
        return $this->dtColumns([
            ['data' => 'id', 'orderable' => false],
            ['data' => 'customer_name'],
            ['data' => 'manufacturer', 'orderable' => false],
            ['data' => 'serial_number'],
            ['data' => 'mac_address', 'orderable' => false],
            ['data' => 'rx_power_dbm', 'orderable' => false],
            ['data' => 'online_duration_text'],
            ['data' => 'device_uptime_seconds', 'orderable' => false],
            ['data' => 'status_value'],
        ]);
    }

    public function test_view_only_admin_can_load_the_datatable(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $this->actingAs($viewer)
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();
    }

    public function test_a_user_with_no_permission_and_no_reseller_membership_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertForbidden();
    }

    public function test_response_contract_has_the_standard_datatables_envelope(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 7, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk()
            ->assertJsonStructure([
                'draw', 'recordsTotal', 'recordsFiltered',
                'data' => [['id', 'customer_name', 'manufacturer', 'serial_number', 'mac_address', 'rx_power_dbm', 'online_duration_text', 'device_uptime_seconds', 'status_value', 'status_label']],
            ]);

        $this->assertSame(7, $response->json('draw'));
        $this->assertSame(3, $response->json('recordsTotal'));
        $this->assertSame(3, $response->json('recordsFiltered'));
        $this->assertCount(3, $response->json('data'));
    }

    public function test_response_never_leaks_customer_pii_beyond_the_whitelisted_columns(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'nik' => '3201010101010001']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('nik', $row);
        $this->assertArrayNotHasKey('customer', $row);
        $this->assertArrayNotHasKey('reseller', $row);
        $this->assertStringNotContainsString('3201010101010001', $response->getContent());
    }

    public function test_pagination_length_and_start_are_honored(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->count(25)->sequence(fn ($seq) => ['serial_number' => 'SNPAGE'.str_pad((string) $seq->index, 3, '0', STR_PAD_LEFT)])
            ->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 10, 'length' => 10,
                'columns' => $this->baseColumns(),
                'order' => [['column' => 3, 'dir' => 'asc']],
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(25, $response->json('recordsTotal'));
        $this->assertCount(10, $response->json('data'));
        // Sorted ascending by serial_number, page 2 (start=10) — rows 10..19.
        $this->assertSame('SNPAGE010', $response->json('data.0.serial_number'));
        $this->assertSame('SNPAGE019', $response->json('data.9.serial_number'));
    }

    public function test_global_search_filters_by_serial_number_case_insensitively(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SnMiXedCaSe001']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'serial_number' => 'SNOTHER999']);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => 'snmixedcase', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame('SnMiXedCaSe001', $response->json('data.0.serial_number'));
    }

    public function test_global_search_also_matches_by_customer_name(): void
    {
        $tenant = Tenant::factory()->create();
        $matching = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso Findable']);
        $other = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Siti Aminah']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $matching->id, 'serial_number' => 'SNCUST1']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $other->id, 'serial_number' => 'SNCUST2']);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => 'findable', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame('SNCUST1', $response->json('data.0.serial_number'));
    }

    public function test_ordering_by_the_computed_customer_name_subquery_column_works(): void
    {
        $tenant = Tenant::factory()->create();
        $customerA = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Zulkifli']);
        $customerB = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Andi']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerA->id, 'serial_number' => 'SNORDA']);
        CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customerB->id, 'serial_number' => 'SNORDB']);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'order' => [['column' => 1, 'dir' => 'asc']],
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame('Andi', $response->json('data.0.customer_name'));
        $this->assertSame('Zulkifli', $response->json('data.1.customer_name'));
    }

    public function test_ordering_by_online_duration_sorts_via_status_changed_at(): void
    {
        $tenant = Tenant::factory()->create();
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id, 'serial_number' => 'SNRECENT',
            'status' => CpeDeviceStatus::Online, 'status_changed_at' => now()->subMinutes(5),
        ]);
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id, 'serial_number' => 'SNOLDER',
            'status' => CpeDeviceStatus::Online, 'status_changed_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($this->admin($tenant))
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'order' => [['column' => 6, 'dir' => 'asc']],
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        // Ascending status_changed_at → the one online LONGEST (oldest
        // status_changed_at) sorts first.
        $this->assertSame('SNOLDER', $response->json('data.0.serial_number'));
        $this->assertSame('SNRECENT', $response->json('data.1.serial_number'));
    }

    public function test_a_reseller_only_sees_their_own_devices_never_another_resellers(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        CpeDevice::factory()->forReseller($resellerA)->create(['serial_number' => 'SNMINE']);
        CpeDevice::factory()->forReseller($resellerB)->create(['serial_number' => 'SNNOTMINE']);

        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerA->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $response = $this->actingAs($owner)
            ->getJson('/api/internal/cpe-devices/datatable?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 10,
                'columns' => $this->baseColumns(),
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(1, $response->json('recordsTotal'));
        $this->assertSame('SNMINE', $response->json('data.0.serial_number'));
    }
}
