<?php

namespace Tests\Feature\Network;

use App\Livewire\Network\CpeDeviceStatusCheck;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\LegacyMacCustomerMap;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Cek Status Device" (v0.7.6-follow-up) — admin-only self-service
 * diagnostic walking the same 4-stage pipeline LegacyDeviceMatcherService
 * depends on. The OUI-vs-tail-distance scenario below reproduces the real
 * ZTEGCB399CEB/Sartimin case (2026-08-12) this page was built to surface
 * automatically instead of needing another manual tinker session.
 */
class CpeDeviceStatusCheckTest extends TestCase
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

    public function test_non_admin_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test(CpeDeviceStatusCheck::class)
            ->assertForbidden();
    }

    /**
     * Deliberately admin-only, NOT reusing CpeDevicePolicy::viewAny()'s
     * reseller carve-out — a reseller owner would pass viewAny() for
     * /cpe-devices but must still be forbidden here, since this page
     * exposes legacy-import/matching internals.
     */
    public function test_reseller_owner_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($owner)
            ->test(CpeDeviceStatusCheck::class)
            ->assertForbidden();
    }

    public function test_admin_can_render_the_page(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->assertOk()
            ->assertSee('Cek Status Device');
    }

    public function test_empty_inputs_shows_a_validation_error(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->set('serialNumber', '')
            ->set('phoneNumber', '')
            ->call('check')
            ->assertHasErrors(['serialNumber']);
    }

    public function test_device_never_seen_in_genieacs_shows_honest_guidance(): void
    {
        Http::fake(['genieacs-nbi:7557/devices*' => Http::response([], 200)]);
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->set('serialNumber', 'NEVERSEEN123')
            ->call('check')
            ->assertSee('Tidak ditemukan sama sekali di GenieACS')
            ->assertSee('belum pernah berhasil inform');
    }

    /**
     * Reproduces the real F663NV9/ZTEGCB399CEB/Sartimin case exactly:
     * customer's own legacy MAC has an OUI matching the device's own
     * reported OUI, but its tail hex-distance to the serial is way outside
     * LegacyDeviceMatcherService's MAX_HEX_DISTANCE=2 tolerance — the page
     * must surface the OUI match as a strong "bind manually" hint, not
     * just a flat "no match".
     */
    public function test_oui_match_but_tail_mismatch_surfaces_manual_bind_suggestion(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => '347839-F663NV9-ZTEGCB399CEB',
                '_deviceId' => ['_OUI' => '347839', '_ProductClass' => 'F663NV9', '_SerialNumber' => 'ZTEGCB399CEB'],
                '_lastInform' => now()->toIso8601String(),
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sartimin',
            'phone_number' => '085156034227',
            'legacy_username' => '085156034227',
        ]);
        LegacyMacCustomerMap::factory()->create([
            'mac_address' => '34:78:39:B5:B3:A2',
            'legacy_username' => '085156034227',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->set('serialNumber', 'ZTEGCB399CEB')
            ->set('phoneNumber', '085156034227')
            ->call('check');

        $component->assertSee('Ditemukan di GenieACS')
            ->assertSee('SAMA PERSIS dengan OUI device di GenieACS')
            ->assertSee('Ganti Modem')
            ->assertSee('Sartimin')
            ->assertSee('Belum ada baris cpe_devices');

        $this->assertFalse($component->get('result')['mac_map']['passed']);
        $this->assertTrue($component->get('result')['customer']['passed']);
        $this->assertSame($customer->id, $component->get('result')['customer']['customer']->id);
    }

    public function test_serial_only_input_auto_derives_customer_when_within_tolerance(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
                '_lastInform' => now()->toIso8601String(),
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone_number' => '081234567890',
            'legacy_username' => '081234567890',
        ]);
        // Tail of ZICG296C2E7B (strip leading letters "ZICG") = "6C2E7B".
        // Exact match (distance 0) against a MAC with the same tail.
        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'AA:BB:CC:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->set('serialNumber', 'ZICG296C2E7B')
            ->call('check');

        $component->assertSee('dalam toleransi auto-matcher')
            ->assertSee('diturunkan otomatis');

        $this->assertTrue($component->get('result')['mac_map']['passed']);
        $this->assertTrue($component->get('result')['customer']['passed']);
        $this->assertSame($customer->id, $component->get('result')['customer']['customer']->id);
    }

    public function test_fully_bound_device_shows_all_four_steps_passing(): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICGBE0001AB',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICGBE0001AB'],
                '_lastInform' => now()->toIso8601String(),
            ]], 200),
        ]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone_number' => '089900011122',
            'legacy_username' => '089900011122',
        ]);
        // Tail of ZICGBE0001AB (strip leading letters "ZICG") = "0001AB" —
        // exact match (distance 0) against this MAC's own tail.
        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'AA:BB:CC:00:01:AB',
            'legacy_username' => '089900011122',
        ]);
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICGBE0001AB',
            'serial_number' => 'ZICGBE0001AB',
        ]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CpeDeviceStatusCheck::class)
            ->set('serialNumber', 'ZICGBE0001AB')
            ->set('phoneNumber', '089900011122')
            ->call('check');

        $result = $component->get('result');
        $this->assertTrue($result['genieacs']['passed']);
        $this->assertTrue($result['customer']['passed']);
        $this->assertTrue($result['binding']['passed']);
        $component->assertSee('Sudah ter-bind');
    }
}
