<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeSignalHistoryGraph;
use App\Models\CpeDevice;
use App\Models\CpeSignalHistory;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.8.3 — fixtures mirror the real shapes confirmed against the live
 * fleet during this sprint's checkpoint (see CLAUDE.md's "RX Power History
 * (v0.8.3)"): device #88's real recorded value (-22.076083105017,
 * confirmed byte-for-byte identical to a live resolver call) for the 'ok'
 * case, and device #138's real confirmed gap (a catalogued device whose
 * refresh genuinely came back with no reading) for the 'all_null' case.
 */
class CpeSignalHistoryGraphLivewireTest extends TestCase
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

    public function test_renders_chart_with_real_shaped_history_including_a_gap(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -22.076083105017,
            'recorded_at' => now()->subHours(2),
        ]);
        // A real, confirmed gap point (device #138's actual outcome) —
        // must still render as part of the series (Chart.js's own
        // spanGaps: false breaks the line there), not be filtered out.
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => null,
            'recorded_at' => now()->subHours(1),
        ]);
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -22.9,
            'recorded_at' => now()->subMinutes(10),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'ok')
            ->assertCount('series', 3)
            ->assertSet('series.0.rx_power_dbm', -22.076083105017)
            ->assertSet('series.1.rx_power_dbm', null)
            ->assertSet('series.2.rx_power_dbm', -22.9);
    }

    public function test_zero_rows_at_all_shows_no_history_state(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'no_history')
            ->assertSet('series', []);
    }

    public function test_rows_exist_but_all_null_in_range_shows_distinct_state(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => null,
            'recorded_at' => now()->subHours(3),
        ]);
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => null,
            'recorded_at' => now()->subHours(1),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'all_null');
    }

    public function test_history_outside_the_24_hour_range_is_excluded(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -25.0,
            'recorded_at' => now()->subDays(3),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'no_history')
            ->assertSet('series', []);
    }

    public function test_another_tenants_device_is_not_viewable(): void
    {
        $ownerTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $ownerTenant->id]);

        // findOrFail() runs against the default (tenant-scoped) query — the
        // other tenant's admin can't even resolve the model, same as every
        // other tenant-isolation guarantee in this codebase (TenantScope
        // filters it out before CpeDevicePolicy::view() is ever reached).
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin($otherTenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id]);
    }

    public function test_main_graph_never_shows_a_range_selector(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertDontSee('role="tab"', false)
            ->assertSee('RX Power')
            ->assertSee('Riwayat');
    }

    public function test_modal_is_closed_by_default_and_opens_on_demand(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('showHistoryModal', false)
            ->call('openHistoryModal')
            ->assertSet('showHistoryModal', true)
            ->assertSet('modalRange', 'day')
            ->call('closeHistoryModal')
            ->assertSet('showHistoryModal', false);
    }

    public function test_opening_the_modal_loads_the_default_day_range(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -20.0,
            'recorded_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->assertSet('modalState', 'ok')
            ->assertCount('modalSeries', 1);
    }

    public function test_changing_the_modal_range_reloads_only_the_modal_series(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        // Outside the default Day (24h) window but inside Week (7d) — must
        // still be excluded from the main page graph's own fixed Day series.
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -23.0,
            'recorded_at' => now()->subDays(3),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->assertSet('state', 'no_history')
            ->call('openHistoryModal')
            ->assertSet('modalState', 'no_history')
            ->call('changeModalRange', 'week')
            ->assertSet('modalRange', 'week')
            ->assertSet('modalState', 'ok')
            // Main page graph's own state/series is untouched by the
            // modal's own range switch — the two are fully independent.
            ->assertSet('state', 'no_history')
            ->assertSet('series', []);
    }

    public function test_changing_the_modal_range_dispatches_the_modal_specific_event(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -19.0,
            'recorded_at' => now()->subMinutes(5),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('changeModalRange', 'hour')
            ->assertDispatched('signal-history-modal-series-updated')
            ->assertNotDispatched('signal-history-series-updated');
    }

    public function test_an_invalid_modal_range_value_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(\ValueError::class);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('changeModalRange', 'not-a-real-range');
    }

    public function test_modal_no_history_message_is_specific_to_the_selected_range(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('changeModalRange', 'year')
            ->assertSee('periode Tahun');
    }

    /**
     * Integration-level check, not just the component in isolation — the
     * CPE detail page (cpe-devices/show.blade.php) is rendered via a
     * nested view()->render() call (see cpe-devices/page.blade.php's own
     * docblock for a real, previously-hit rendering-order bug in that
     * exact mechanism) — confirms @livewire() embedded inside it still
     * hydrates correctly end to end, not just that the component class
     * itself behaves correctly in isolation.
     */
    public function test_graph_renders_correctly_embedded_in_the_real_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -21.5,
            'recorded_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$device->id}")
            ->assertOk()
            ->assertSee('RX Power')
            ->assertSee('Riwayat')
            ->assertSee('signalHistoryChart', false);

        $response->assertDontSee('Riwayat RX Power', false);
    }

    /**
     * Layout revision (this sprint) — the graph moved out of the "Status
     * Jaringan" two-column panel into its own full-width section between
     * that grid and "WiFi / SSID", not just "somewhere on the page".
     * Asserted positionally against the real rendered HTML, not just
     * presence, since "the text exists somewhere" wouldn't have caught the
     * original request (it was already present inside Status Jaringan
     * before this move).
     */
    public function test_graph_section_sits_between_the_status_grid_and_wifi_section(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -21.5,
            'recorded_at' => now()->subMinutes(30),
        ]);

        $html = $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$device->id}")
            ->assertOk()
            ->getContent();

        $attachedVlansPos = strpos($html, 'Attached VLANs');
        // The plain "RX Power" <dt> field label (inside the Status
        // Jaringan panel's own <dl>, unrelated to this section) also
        // literally contains ">RX Power<" — search starting AFTER
        // Attached VLANs specifically to land on the NEW section's own
        // <h2> title, not that pre-existing field label.
        $rxPowerTitlePos = strpos($html, '>'.__('RX Power').'<', $attachedVlansPos);
        $wifiSectionPos = strpos($html, 'WiFi / SSID');

        $this->assertNotFalse($attachedVlansPos);
        $this->assertNotFalse($rxPowerTitlePos);
        $this->assertNotFalse($wifiSectionPos);
        $this->assertTrue(
            $attachedVlansPos < $rxPowerTitlePos && $rxPowerTitlePos < $wifiSectionPos,
            'Expected order: Attached VLANs (end of the Status Jaringan grid) < RX Power section < WiFi / SSID.'
        );
    }

    // v0.8.3 — Custom Date Range 6th tab (CLAUDE.md), shared via
    // App\Livewire\Concerns\ValidatesCustomHistoryRange +
    // CpeSignalHistoryQueryService::customSeriesFor().

    public function test_selecting_the_custom_tab_shows_inputs_without_loading_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -20.0,
            'recorded_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            // Selecting the tab alone must not query anything yet — the
            // modal's series/state stay whatever they already were.
            ->assertSet('modalState', 'ok');
    }

    public function test_apply_custom_range_loads_the_matching_series(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $target = now()->subMonths(3);

        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -18.5,
            'recorded_at' => $target,
        ]);
        // Outside the custom window entirely — must never appear.
        CpeSignalHistory::factory()->create([
            'cpe_device_id' => $device->id,
            'rx_power_dbm' => -99.0,
            'recorded_at' => now()->subMonths(6),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', $target->copy()->subDay()->toDateString())
            ->set('customTo', $target->copy()->addDay()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('modalState', 'ok')
            ->assertCount('modalSeries', 1)
            ->assertSet('modalSeries.0.rx_power_dbm', -18.5)
            ->assertDispatched('signal-history-modal-series-updated');
    }

    public function test_custom_range_with_to_before_from_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->toDateString())
            ->set('customTo', now()->subDay()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('customRangeError', '"Sampai" tidak boleh sebelum "Dari".');
    }

    public function test_custom_range_over_two_years_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->set('customFrom', now()->subYears(3)->toDateString())
            ->set('customTo', now()->toDateString())
            ->call('applyCustomRange')
            ->assertSet('customRangeError', 'Rentang maksimum adalah 2 tahun.');
    }

    public function test_custom_range_with_empty_dates_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->call('applyCustomRange')
            ->assertSet('customRangeError', 'Tanggal "Dari" dan "Sampai" wajib diisi.');
    }

    public function test_choosing_a_preset_tab_after_custom_exits_custom_mode(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeSignalHistoryGraph::class, ['cpeDeviceId' => $device->id])
            ->call('openHistoryModal')
            ->call('selectCustomRangeTab')
            ->assertSet('customRangeMode', true)
            ->call('changeModalRange', 'week')
            ->assertSet('customRangeMode', false)
            ->assertSet('modalRange', 'week');
    }
}
