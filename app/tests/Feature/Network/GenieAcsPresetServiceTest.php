<?php

namespace Tests\Feature\Network;

use App\Models\RemoteWanConfig;
use App\Services\Network\GenieAcsPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * GenieACS Auto-WAN — service yang menulis provision `default-wan` + preset
 * TERPISAH `boss-auto-wan` (bukan di-fold ke `default`) ke genieacs-nbi
 * lewat REST. Semua HTTP di-fake.
 */
class GenieAcsPresetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GenieAcsPresetService
    {
        return new GenieAcsPresetService('http://genieacs-nbi:7557');
    }

    private function fakeOk(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([['_id' => 'default-wan']], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([], 200),
        ]);
    }

    public function test_enabled_with_empty_allowlists_puts_a_fleet_wide_preset(): void
    {
        $this->fakeOk();

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => true, 'wan1_vlan' => 10, 'wan1_pppoe_username' => 'boss']);

        $this->service()->syncAutoWanConfig($config->fresh());

        // Script provision di-PUT.
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/provisions/default-wan')
            && str_contains((string) $r->body(), 'Auto-WAN provisioning'));

        // Preset boss-auto-wan di-PUT, precondition "true" (fleet-wide).
        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/boss-auto-wan')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $this->assertSame('true', $body['precondition']);
            $this->assertSame('boss-auto-wan', $body['channel']);
            $wan = $body['configurations'][0];
            $this->assertSame('default-wan', $wan['name']);
            $this->assertSame([true, true, 10, 'boss', 'default', false, 1200, ''], $wan['args']);

            return true;
        });
    }

    public function test_enabled_with_a_serial_allowlist_scopes_the_precondition(): void
    {
        $this->fakeOk();

        $config = RemoteWanConfig::current();
        $config->update([
            'enabled' => true,
            'wan1_serial_allowlist' => "ZTEGC1234567\nHWTC0089ABCD",
            'wan2_serial_allowlist' => "CMDC00AA11BB\nZTEGC1234567", // overlap di-dedup
        ]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/boss-auto-wan')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $this->assertSame(
                'DeviceID.SerialNumber = "ZTEGC1234567" OR DeviceID.SerialNumber = "HWTC0089ABCD" OR DeviceID.SerialNumber = "CMDC00AA11BB"',
                $body['precondition']
            );

            return true;
        });
    }

    public function test_disabled_deletes_the_preset_but_keeps_the_provision(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([], 200),
            'genieacs-nbi:7557/presets/boss-auto-wan' => Http::response('', 200),
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => false]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/provisions/default-wan'));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/presets/boss-auto-wan'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/presets/boss-auto-wan'));
    }

    public function test_disabled_tolerates_a_404_on_preset_delete(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([], 200),
            'genieacs-nbi:7557/presets/boss-auto-wan' => Http::response(['error' => 'not found'], 404),
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => false]);

        // Tidak melempar.
        $this->service()->syncAutoWanConfig($config->fresh());
        $this->assertTrue(true);
    }

    public function test_it_throws_a_user_facing_error_when_genieacs_rejects_the_preset_put(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([], 200),
            'genieacs-nbi:7557/presets/*' => Http::response(['error' => 'invalid precondition'], 400),
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('menolak update preset');

        $this->service()->syncAutoWanConfig($config->fresh());
    }

    public function test_inspect_reports_live_state(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([['_id' => 'default-wan']], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([[
                '_id' => 'boss-auto-wan',
                'precondition' => 'DeviceID.SerialNumber = "SN1"',
                'configurations' => [['type' => 'provision', 'name' => 'default-wan', 'args' => [true, true, 10, 'default', 'default', false, 1200, 'SN1']]],
            ]], 200),
        ]);

        $state = $this->service()->inspectAutoWanState();

        $this->assertTrue($state['provision_exists']);
        $this->assertTrue($state['preset_exists']);
        $this->assertSame('DeviceID.SerialNumber = "SN1"', $state['precondition']);
        $this->assertSame([true, true, 10, 'default', 'default', false, 1200, 'SN1'], $state['preset_args']);
    }

    public function test_inspect_reports_preset_absent_when_auto_wan_off(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([['_id' => 'default-wan']], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([], 200),
        ]);

        $state = $this->service()->inspectAutoWanState();

        $this->assertTrue($state['provision_exists']);
        $this->assertFalse($state['preset_exists']);
        $this->assertNull($state['precondition']);
    }

    public function test_auto_wan_script_reads_the_canonical_file_with_the_v2_guards(): void
    {
        $script = $this->service()->autoWanScript();

        $this->assertStringContainsString('KONTRAK ARGS', $script);
        $this->assertStringContainsString('isHuawei', $script);
        $this->assertStringContainsString('isCMCC', $script);
        $this->assertStringContainsString('isZTEGeneric', $script);
        // Cabang CT-COM (data model dominan fleet ini) + VLAN level-WCD.
        $this->assertStringContainsString('isCTCom', $script);
        $this->assertStringContainsString('X_CT-COM_WANGponLinkConfig.VLANIDMark', $script);
        $this->assertStringContainsString('ctcFreeWcd', $script);
        // WAN2 guard v2 — cek isi (bridge di posisi mana pun) + SN allowlist in-script.
        $this->assertStringContainsString('bridgeWithTargetVlanExists', $script);
        $this->assertStringContainsString('wan2Allowlist', $script);
    }

    public function test_serial_list_is_normalized_into_the_args_csv(): void
    {
        $this->fakeOk();

        $config = RemoteWanConfig::current();
        $config->update([
            'enabled' => true, 'wan2_enabled' => true,
            'wan2_serial_allowlist' => "ZTEGC1234567\n  HWTC0089ABCD  \nZTEGC1234567\n\nCMDC00AA11BB",
        ]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/boss-auto-wan')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $this->assertSame('ZTEGC1234567,HWTC0089ABCD,CMDC00AA11BB', $body['configurations'][0]['args'][7]);

            return true;
        });
    }
}
