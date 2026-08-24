<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\ContainerStatsList;
use App\Models\ContainerStatsHistory;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContainerStatsListLivewireTest extends TestCase
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

    public function test_shows_only_the_latest_snapshot_per_container(): void
    {
        $older = now()->subMinutes(5);
        $latest = now();

        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'cpu_percent' => 1.0, 'recorded_at' => $older]);
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'cpu_percent' => 2.0, 'recorded_at' => $latest]);
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-worker', 'cpu_percent' => 3.0, 'recorded_at' => $latest]);

        Livewire::actingAs($this->admin())
            ->test(ContainerStatsList::class)
            ->assertSet('noData', false)
            ->assertCount('rows', 2)
            ->assertSet('rows.0.container_name', 'boss-app')
            ->assertSet('rows.0.cpu_percent', 2.0)
            ->assertSet('rows.1.container_name', 'boss-worker');
    }

    public function test_zero_rows_shows_no_data_state(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ContainerStatsList::class)
            ->assertSet('noData', true)
            ->assertSet('rows', []);
    }

    public function test_guest_without_permission_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('billing');

        Livewire::actingAs($user)
            ->test(ContainerStatsList::class)
            ->assertForbidden();
    }

    // v0.8.3 — VPN/LibreNMS/BOSS App Core/Lainnya grouping.

    public function test_rows_are_split_into_the_correct_groups(): void
    {
        $latest = now();

        foreach (['openvpn', 'wireguard-node2'] as $name) {
            ContainerStatsHistory::factory()->create(['container_name' => $name, 'recorded_at' => $latest]);
        }
        foreach (['librenms', 'librenms-db'] as $name) {
            ContainerStatsHistory::factory()->create(['container_name' => $name, 'recorded_at' => $latest]);
        }
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'recorded_at' => $latest]);
        ContainerStatsHistory::factory()->create(['container_name' => 'mongo', 'recorded_at' => $latest]);

        Livewire::actingAs($this->admin())
            ->test(ContainerStatsList::class)
            ->assertCount('groupedRows.VPN', 2)
            ->assertCount('groupedRows.LibreNMS', 2)
            ->assertCount('groupedRows.BOSS App Core', 1)
            ->assertCount('groupedRows.Lainnya', 1)
            ->assertSet('groupedRows.Lainnya.0.container_name', 'mongo');
    }

    public function test_no_container_is_ever_lost_a_totally_unknown_container_lands_in_lainnya(): void
    {
        $latest = now();

        ContainerStatsHistory::factory()->create(['container_name' => 'some-brand-new-future-service', 'recorded_at' => $latest]);

        $component = Livewire::actingAs($this->admin())
            ->test(ContainerStatsList::class)
            ->assertCount('rows', 1)
            ->assertCount('groupedRows.Lainnya', 1)
            ->assertSet('groupedRows.Lainnya.0.container_name', 'some-brand-new-future-service');

        // No other group silently swallowed it either — total count across
        // every group present must match the flat row count exactly.
        $totalGrouped = array_sum(array_map('count', $component->get('groupedRows')));
        $this->assertSame(1, $totalGrouped);
    }

    public function test_a_group_with_zero_matching_containers_is_absent_from_grouped_rows(): void
    {
        ContainerStatsHistory::factory()->create(['container_name' => 'boss-app', 'recorded_at' => now()]);

        $component = Livewire::actingAs($this->admin())
            ->test(ContainerStatsList::class)
            ->assertCount('groupedRows.BOSS App Core', 1);

        $groupedRows = $component->get('groupedRows');

        $this->assertSame(['BOSS App Core'], array_keys($groupedRows));
    }
}
