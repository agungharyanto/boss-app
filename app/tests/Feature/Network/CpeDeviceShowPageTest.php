<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\CpeDeviceModelCapability;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /cpe-devices/{id} (2026-08-16) — the standalone detail page that
 * replaces the old DataTables child-row expand interaction. Shares
 * CpeDeviceDetailController::loadDetailData()/the same _actions-and-history
 * partial as CpeDeviceDetailControllerTest's child-row coverage, so this
 * file focuses on what's NEW here: the full-page layout wrapper, Attached
 * VLANs / PPPoE username / WiFi SSID list / Ethernet ports sections, and
 * the on-demand PPPoE password reveal endpoint.
 */
class CpeDeviceShowPageTest extends TestCase
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

    /**
     * Real shape confirmed on A4F33B-GM219-ZICG298BF2F9 (2026-08-16) — a
     * genuine WANPPPConnection instance at a non-fixed index (WCD.3),
     * Username populated, Password empty (same vendor "password reads back
     * empty" behavior already documented for WiFi passwords).
     */
    private function fakeDeviceWithPppoe(): array
    {
        return [
            '_id' => 'A4F33B-GM219-ZICG298BF2F9',
            '_deviceId' => ['_OUI' => 'A4F33B', '_ProductClass' => 'GM219', '_SerialNumber' => 'ZICG298BF2F9'],
            'InternetGatewayDevice' => [
                'WANDevice' => ['1' => ['WANConnectionDevice' => [
                    '3' => ['WANPPPConnection' => ['1' => [
                        'Name' => ['_value' => '1_INTERNET_R_VID_131'],
                        'ConnectionStatus' => ['_value' => 'Connected'],
                        'Username' => ['_value' => '083128836762'],
                        'Password' => ['_value' => ''],
                    ]]],
                ]]],
                'LANDevice' => ['1' => ['WLANConfiguration' => [
                    '1' => ['SSID' => ['_value' => 'HomeWifi'], 'Enable' => ['_value' => true]],
                    '5' => ['SSID' => ['_value' => 'HomeWifi-5G'], 'Enable' => ['_value' => false]],
                ]]],
            ],
        ];
    }

    public function test_page_shows_wan_connection_pppoe_username_and_wlan_list(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeDeviceWithPppoe()], 200)]);

        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);

        $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$device->id}")
            ->assertOk()
            ->assertSee('1_INTERNET_R_VID_131')
            ->assertSee('Connected')
            ->assertSee('083128836762')
            ->assertSee('HomeWifi')
            ->assertSee('HomeWifi-5G');
    }

    /**
     * Regression test for a real bug caught only via a Playwright screenshot,
     * never by assertSee() (which does a plain substring match and finds
     * "Ganti WiFi" whether or not it's wrapped in real `<div>` tags or
     * double-HTML-escaped `&lt;div&gt;` text) — see
     * CpeDeviceDetailController::page()'s own docblock for the full root
     * cause (Illuminate\View\Factory::renderCount hitting 0 between two
     * separate top-level render() calls). Asserts the raw response body
     * directly for the specific escaping signature and the presence of a
     * REAL, executable <script> tag.
     */
    public function test_page_renders_as_real_html_not_double_escaped(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->get("/cpe-devices/{$device->id}");

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('&lt;div', $content);
        $this->assertStringNotContainsString('&lt;button', $content);
        $this->assertStringContainsString('<script>', $content);
        $this->assertStringContainsString('window.cpeRevealPppoePassword = function', $content);
    }

    public function test_page_never_embeds_pppoe_password_in_initial_html(): void
    {
        $device = $this->fakeDeviceWithPppoe();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['3']['WANPPPConnection']['1']['Password']['_value'] = 'realsecretpassword';

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $tenant = Tenant::factory()->create();
        $cpeDevice = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);

        $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$cpeDevice->id}")
            ->assertOk()
            ->assertDontSee('realsecretpassword');
    }

    public function test_page_shows_dash_for_sections_with_no_discovered_data(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([[
            '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
            '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
            'InternetGatewayDevice' => [],
        ]], 200)]);

        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);

        $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$device->id}")
            ->assertOk()
            ->assertSee('Detail Perangkat CPE');
    }

    /**
     * "Ganti WiFi" (2026-08-17) no longer has a section literally labeled
     * that — it's now a per-row "Edit" toggle inside the WiFi/SSID table
     * (cpeSubmitWifi(...) onclick is the reliable marker that the form
     * exists), only rendered per row when $canManage AND that SSID index
     * was actually discovered — hence a real WLANConfiguration fixture via
     * Http::fake() here, not an empty device. "Ganti Modem" stays literal
     * text (moved next to Serial Number, not data-dependent).
     */
    public function test_reboot_ganti_wifi_ganti_modem_and_remove_only_visible_to_manage_admin(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeDeviceWithPppoe()], 200)]);

        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'cpe_devices.view', 'guard_name' => 'web']));

        $viewerResponse = $this->actingAs($viewer)->get("/cpe-devices/{$device->id}")->assertOk();
        $viewerResponse->assertDontSee('cpeSubmitWifi(', false);
        $viewerResponse->assertDontSee('Ganti Modem');

        $adminResponse = $this->actingAs($this->admin($tenant))->get("/cpe-devices/{$device->id}")->assertOk();
        $adminResponse->assertSee('cpeSubmitWifi('.$device->id.', 1)', false);
        $adminResponse->assertSee('Ganti Modem');
    }

    /**
     * 2026-08-19 (Bagian A) — Reboot/Remove/Sync Sekarang moved to the
     * very bottom of the page, after Riwayat Aksi and Client Terhubung
     * (previously they rendered first, above both). Asserted by raw
     * string position in the response body, not just presence.
     */
    public function test_reboot_remove_and_sync_now_render_after_riwayat_aksi_and_client_terhubung(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $content = $this->actingAs($this->admin($tenant))
            ->get("/cpe-devices/{$device->id}")
            ->assertOk()
            ->getContent();

        $riwayatPos = strpos($content, 'Riwayat Aksi');
        $clientPos = strpos($content, 'Client Terhubung');
        $syncPos = strpos($content, 'cpeSyncNow(');
        $rebootPos = strpos($content, 'cpeReboot(');
        $removePos = strpos($content, 'cpeRemove(');

        $this->assertNotFalse($riwayatPos);
        $this->assertNotFalse($clientPos);
        $this->assertNotFalse($syncPos);
        $this->assertNotFalse($rebootPos);
        $this->assertNotFalse($removePos);

        $this->assertGreaterThan($riwayatPos, $syncPos);
        $this->assertGreaterThan($clientPos, $syncPos);
        $this->assertGreaterThan($clientPos, $rebootPos);
        $this->assertGreaterThan($clientPos, $removePos);
    }

    public function test_sync_now_endpoint_creates_a_delivered_log_with_both_task_ids(): void
    {
        Http::fake(['genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-sync'], 202)]);

        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B']);

        $response = $this->actingAs($this->admin($tenant))
            ->postJson("/api/internal/cpe-devices/{$device->id}/sync-now")
            ->assertOk();

        $this->assertStringContainsString('terkirim', $response->json('message'));
        $this->assertDatabaseHas('cpe_action_logs', ['cpe_device_id' => $device->id, 'action_type' => 'sync_now', 'status' => 'delivered']);
    }

    /**
     * Each WiFi/SSID row gets its own independent Alpine collapse scope
     * (a dedicated <tbody x-data="{open:false}"> per row — see
     * show.blade.php's own docblock for why a single shared scope would
     * have opened/closed every row's form together) and its own
     * Aktif/Nonaktif toggle wired to the real per-row enabled state.
     *
     * A4F33B/GM219 has a real cpe_device_model_capabilities row
     * (max_ssid_slots=4) — combined with real data at index 1 and 5 (index
     * 5 beyond that catalog max, still shown per resolveWlanConfigurations()'s
     * own "never drop real data" rule), this renders 5 rows total: 1 and 5
     * real, 2/3/4 padded empty placeholders (2026-08-19, Bagian B).
     */
    public function test_wifi_ssid_table_renders_independent_collapse_and_toggle_per_row(): void
    {
        CpeDeviceModelCapability::factory()->create([
            'oui' => 'A4F33B',
            'product_class' => 'GM219',
            'max_ssid_slots' => 4,
        ]);

        $device = $this->fakeDeviceWithPppoe();
        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $tenant = Tenant::factory()->create();
        $cpeDevice = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);

        $response = $this->actingAs($this->admin($tenant))->get("/cpe-devices/{$cpeDevice->id}")->assertOk();
        $content = $response->getContent();

        // One <tbody x-data="{ open: false }"> per SSID row — 5 rows total:
        // 1 and 5 have real data, 2/3/4 are padded placeholders.
        $this->assertSame(5, substr_count($content, 'x-data="{ open: false }"'));
        $this->assertSame(3, substr_count($content, '(kosong)'));

        $response->assertSee('cpeToggleSsid('.$cpeDevice->id.', 1, true)', false);
        $response->assertSee('cpeToggleSsid('.$cpeDevice->id.', 5, false)', false);
        $response->assertSee('cpeToggleSsid('.$cpeDevice->id.', 2, false)', false);
        $response->assertSee('cpeSubmitWifi('.$cpeDevice->id.', 5)', false);
        $response->assertSee('cpeSubmitWifi('.$cpeDevice->id.', 2)', false);
    }

    public function test_a_reseller_cannot_view_another_resellers_device_page(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $deviceB = CpeDevice::factory()->forReseller($resellerB)->create();
        $ownerA = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($ownerA)
            ->get("/cpe-devices/{$deviceB->id}")
            ->assertForbidden();
    }

    public function test_pppoe_password_endpoint_returns_real_value_only_on_explicit_request(): void
    {
        $device = $this->fakeDeviceWithPppoe();
        $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['3']['WANPPPConnection']['1']['Password']['_value'] = 'realsecretpassword';

        Http::fake(['*genieacs-nbi*' => Http::response([$device], 200)]);

        $tenant = Tenant::factory()->create();
        $cpeDevice = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);

        $this->actingAs($this->admin($tenant))
            ->getJson("/api/internal/cpe-devices/{$cpeDevice->id}/pppoe-password")
            ->assertOk()
            ->assertJson(['password' => 'realsecretpassword']);
    }

    public function test_pppoe_password_endpoint_returns_null_when_device_reports_empty_password(): void
    {
        Http::fake(['*genieacs-nbi*' => Http::response([$this->fakeDeviceWithPppoe()], 200)]);

        $tenant = Tenant::factory()->create();
        $cpeDevice = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM219-ZICG298BF2F9',
        ]);

        $this->actingAs($this->admin($tenant))
            ->getJson("/api/internal/cpe-devices/{$cpeDevice->id}/pppoe-password")
            ->assertOk()
            ->assertJson(['password' => null]);
    }

    public function test_pppoe_password_endpoint_requires_authorization(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->getJson("/api/internal/cpe-devices/{$device->id}/pppoe-password")
            ->assertForbidden();
    }
}
