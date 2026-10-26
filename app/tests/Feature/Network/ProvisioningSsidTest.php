<?php

namespace Tests\Feature\Network;

use App\Enums\SsidBand;
use App\Enums\SsidSecurity;
use App\Enums\WifiRole;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\ProvisioningProfile;
use App\Models\ProvisioningSsid;
use App\Models\Tenant;
use App\Services\Network\GenieAcsClientService;
use App\Services\Network\Provisioning\CpeAdapterRegistry;
use App\Services\Network\Provisioning\SsidNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v0.23.8.4 — template SSID: resolver placeholder, model/validasi, dan LOGIC
 * peran→instance adapter CT-COM (mock GenieACS, nol sentuh device).
 */
class ProvisioningSsidTest extends TestCase
{
    use RefreshDatabase;

    // ---- SsidNameResolver -------------------------------------------------

    public function test_name_resolver_substitutes_cid_and_name(): void
    {
        $customer = new Customer;
        $customer->cid = '261067914698';
        $customer->name = 'Budi Santoso';
        $resolver = new SsidNameResolver;

        $this->assertSame('261067914698', $resolver->resolve('{cid}', $customer));
        $this->assertSame('Budi Santoso-WiFi', $resolver->resolve('{name}-WiFi', $customer));
        $this->assertSame('TOKEN WIFI', $resolver->resolve('TOKEN WIFI', $customer)); // literal, tanpa placeholder
        $this->assertSame('261067914698 Budi Santoso', $resolver->resolve('{cid} {name}', $customer));
    }

    public function test_name_resolver_leaves_unknown_placeholder_as_is(): void
    {
        $customer = new Customer;
        $customer->cid = 'C1';
        $customer->name = 'N1';

        // {cdi} typo & {foo} tak dikenal → DIBIARKAN, bukan dihapus senyap.
        $this->assertSame('C1 {cdi} {foo}', (new SsidNameResolver)->resolve('{cid} {cdi} {foo}', $customer));
    }

    // ---- model + invariant ------------------------------------------------

    public function test_profile_ssids_relation_and_open_forces_null_password(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = ProvisioningProfile::create(['tenant_id' => $tenant->id, 'name' => 'p', 'slug' => 'p', 'enabled' => true]);

        $open = ProvisioningSsid::create([
            'provisioning_profile_id' => $profile->id,
            'wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band24,
            'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open,
            'password' => 'should-be-wiped', // open → dipaksa null
        ]);

        $this->assertNull($open->fresh()->password);
        $this->assertTrue($profile->ssids->contains($open));
    }

    public function test_wpa2_requires_password(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = ProvisioningProfile::create(['tenant_id' => $tenant->id, 'name' => 'p', 'slug' => 'p', 'enabled' => true]);

        $this->expectException(InvalidArgumentException::class);
        ProvisioningSsid::create([
            'provisioning_profile_id' => $profile->id,
            'wifi_role' => WifiRole::PrimaryWifi, 'band' => SsidBand::Band5,
            'ssid_name' => '{name}', 'security_mode' => SsidSecurity::Wpa2,
            'password' => null,
        ]);
    }

    // ---- LOGIC peran→instance di adapter CT-COM ---------------------------

    private function fakeDevice(array $wlanInstances): array
    {
        $wlan = [];
        foreach ($wlanInstances as $n) {
            $wlan[(string) $n] = ['SSID' => ['_value' => "ssid{$n}"]];
        }

        return ['InternetGatewayDevice' => ['LANDevice' => ['1' => ['WLANConfiguration' => $wlan]]]];
    }

    private function cpe(): CpeDevice
    {
        $cpe = new CpeDevice;
        $cpe->genieacs_device_id = 'DEV';

        return $cpe;
    }

    private function adapterWithGenie(GenieAcsClientService $genie)
    {
        $this->app->instance(GenieAcsClientService::class, $genie);

        return app(CpeAdapterRegistry::class)->for('ctcom');
    }

    public function test_primary_wifi_sets_params_without_addobject(): void
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->fakeDevice([1, 5]));
        // primary 1 & 5 sudah ada → 2 setParameterValues, NOL addObject.
        $tasks = [];
        $genie->expects($this->exactly(2))->method('sendTask')
            ->willReturnCallback(function ($id, $task) use (&$tasks) {
                $tasks[] = $task['name'];

                return ['task_id' => 't'];
            });

        $result = $this->adapterWithGenie($genie)->pushSsid($this->cpe(), [
            ['wifi_role' => WifiRole::PrimaryWifi, 'band' => SsidBand::Band24, 'ssid_name' => 'TEST DONG', 'security_mode' => SsidSecurity::Wpa2, 'password' => '1sampai8'],
            ['wifi_role' => WifiRole::PrimaryWifi, 'band' => SsidBand::Band5, 'ssid_name' => 'TEST DONG', 'security_mode' => SsidSecurity::Wpa2, 'password' => '1sampai8'],
        ]);

        $this->assertSame([1, 5], $result['configured']);
        $this->assertFalse($result['addobject_issued']);
        $this->assertSame(['setParameterValues', 'setParameterValues'], $tasks);
    }

    public function test_guest_wifi_absent_triggers_addobject_and_defers(): void
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->fakeDevice([1, 5])); // 4 & 8 belum ada
        $names = [];
        $genie->method('sendTask')->willReturnCallback(function ($id, $task) use (&$names) {
            $names[] = $task['name'];

            return ['task_id' => 't'];
        });

        $result = $this->adapterWithGenie($genie)->pushSsid($this->cpe(), [
            ['wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band24, 'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open, 'password' => null],
            ['wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band5, 'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open, 'password' => null],
        ]);

        $this->assertSame([], $result['configured']);
        // Guard 5GHz (v0.23.8.6, commit 2be6e2e): guest 2.4g (inst 4) absent ->
        // pending + AddObject; guest 5g (inst 8) absent -> SKIP (device 2.4GHz-only,
        // AddObject tak pernah memunculkan radio 5G), BUKAN pending.
        $this->assertSame([4], $result['pending']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(8, $result['skipped'][0]['instance']);
        $this->assertSame(SsidBand::Band5->value, $result['skipped'][0]['band']);
        $this->assertStringContainsString('device tidak punya instance 5G', $result['skipped'][0]['reason']);
        $this->assertTrue($result['addobject_issued']);
        // Hanya addObject (SATU, untuk 2.4g) — belum ada setParameterValues (instance belum ada).
        $this->assertSame(['addObject'], $names);
    }

    public function test_guest_wifi_present_sets_params_no_addobject(): void
    {
        $genie = $this->createMock(GenieAcsClientService::class);
        $genie->method('findDeviceById')->willReturn($this->fakeDevice([1, 2, 3, 4, 5, 6, 7, 8]));
        $names = [];
        $genie->method('sendTask')->willReturnCallback(function ($id, $task) use (&$names) {
            $names[] = $task['name'];

            return ['task_id' => 't'];
        });

        $result = $this->adapterWithGenie($genie)->pushSsid($this->cpe(), [
            ['wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band24, 'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open, 'password' => null],
            ['wifi_role' => WifiRole::GuestWifi, 'band' => SsidBand::Band5, 'ssid_name' => 'TOKEN WIFI', 'security_mode' => SsidSecurity::Open, 'password' => null],
        ]);

        $this->assertSame([4, 8], $result['configured']);
        $this->assertFalse($result['addobject_issued']);
        $this->assertSame(['setParameterValues', 'setParameterValues'], $names);
    }
}
