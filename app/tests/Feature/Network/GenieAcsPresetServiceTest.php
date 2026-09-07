<?php

namespace Tests\Feature\Network;

use App\Models\RemoteWanConfig;
use App\Services\Network\GenieAcsPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * GenieACS Auto-WAN configurable — service yang menulis provision
 * `default-wan` + `args` preset `default` ke genieacs-nbi lewat REST.
 * Semua HTTP di-fake (tidak pernah menyentuh genieacs-nbi sungguhan).
 */
class GenieAcsPresetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): GenieAcsPresetService
    {
        return new GenieAcsPresetService('http://genieacs-nbi:7557');
    }

    /** Preset `default` yang sudah ada dengan 3 provision statik + satu default-wan lama. */
    private function fakeGenieAcs(array $presetConfigurations): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([['_id' => 'default'], ['_id' => 'default-optical']], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([[
                '_id' => 'default',
                'channel' => 'default',
                'configurations' => $presetConfigurations,
            ]], 200),
        ]);
    }

    public function test_enabled_config_puts_the_script_and_adds_default_wan_to_the_preset_with_fresh_args(): void
    {
        $this->fakeGenieAcs([
            ['type' => 'provision', 'name' => 'default', 'args' => []],
            ['type' => 'provision', 'name' => 'default-optical', 'args' => []],
        ]);

        $config = RemoteWanConfig::current();
        $config->update([
            'enabled' => true, 'wan1_enabled' => true, 'wan1_vlan' => 1234,
            'wan1_pppoe_username' => 'boss', 'wan1_pppoe_password' => 's3cr3t',
            'wan2_enabled' => false, 'wan2_vlan' => 1200,
        ]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/provisions/default-wan')
                && str_contains((string) $request->body(), 'Auto-WAN provisioning');
        });

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/default')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $names = array_column($body['configurations'], 'name');
            // 3 provision statik dipertahankan + default-wan ditambah.
            $this->assertSame(['default', 'default-optical', 'default-wan'], $names);
            $wan = collect($body['configurations'])->firstWhere('name', 'default-wan');
            // Kontrak args posisional: [enabled, wan1_enabled, wan1_vlan, user, pass, wan2_enabled, wan2_vlan]
            $this->assertSame([true, true, 1234, 'boss', 's3cr3t', false, 1200], $wan['args']);

            return true;
        });
    }

    public function test_a_stale_default_wan_entry_is_replaced_not_duplicated(): void
    {
        $this->fakeGenieAcs([
            ['type' => 'provision', 'name' => 'default', 'args' => []],
            ['type' => 'provision', 'name' => 'default-wan', 'args' => [true, true, 999, 'old', 'old', false, 111]],
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => true, 'wan1_vlan' => 2000]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/default')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $wanEntries = array_filter($body['configurations'], fn ($c) => $c['name'] === 'default-wan');
            $this->assertCount(1, $wanEntries);
            $this->assertSame(2000, array_values($wanEntries)[0]['args'][2]);

            return true;
        });
    }

    public function test_disabled_config_removes_default_wan_from_the_preset_entirely(): void
    {
        $this->fakeGenieAcs([
            ['type' => 'provision', 'name' => 'default', 'args' => []],
            ['type' => 'provision', 'name' => 'default-wan', 'args' => [true, true, 999, 'x', 'x', false, 111]],
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => false]);

        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/default')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $names = array_column($body['configurations'], 'name');
            $this->assertNotContains('default-wan', $names);
            $this->assertContains('default', $names);

            return true;
        });

        // Script provision tetap di-PUT (harmless — guard `enabled` di
        // dalam script no-op-kan; provision tetap ada di genieacs).
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/provisions/default-wan'));
    }

    public function test_it_preserves_existing_preset_weight_and_precondition(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([[
                '_id' => 'default', 'channel' => 'default', 'weight' => 5,
                'precondition' => 'DeviceID.ProductClass = "X"',
                'configurations' => [['type' => 'provision', 'name' => 'default', 'args' => []]],
            ]], 200),
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => true]);
        $this->service()->syncAutoWanConfig($config->fresh());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/presets/default')) {
                return false;
            }
            $body = json_decode((string) $request->body(), true);
            $this->assertSame(5, $body['weight']);
            $this->assertSame('DeviceID.ProductClass = "X"', $body['precondition']);

            return true;
        });
    }

    public function test_it_throws_a_user_facing_error_when_genieacs_rejects_the_preset_put(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([], 200),
            'genieacs-nbi:7557/presets/*' => Http::sequence()
                ->push([['_id' => 'default', 'configurations' => []]], 200) // GET
                ->push(['error' => 'invalid precondition'], 400),           // PUT
        ]);

        $config = RemoteWanConfig::current();
        $config->update(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('menolak update preset');

        $this->service()->syncAutoWanConfig($config->fresh());
    }

    public function test_inspect_reports_live_preset_state(): void
    {
        Http::fake([
            'genieacs-nbi:7557/provisions/*' => Http::response([['_id' => 'default-wan']], 200),
            'genieacs-nbi:7557/presets/*' => Http::response([[
                '_id' => 'default',
                'configurations' => [['type' => 'provision', 'name' => 'default-wan', 'args' => [true, true, 1000, 'default', 'default', false, 1200]]],
            ]], 200),
        ]);

        $state = $this->service()->inspectAutoWanState();

        $this->assertTrue($state['provision_exists']);
        $this->assertTrue($state['in_preset']);
        $this->assertSame([true, true, 1000, 'default', 'default', false, 1200], $state['preset_args']);
    }

    public function test_auto_wan_script_reads_the_canonical_file(): void
    {
        $script = $this->service()->autoWanScript();

        $this->assertStringContainsString('KONTRAK ARGS', $script);
        $this->assertStringContainsString('isHuawei', $script);
        $this->assertStringContainsString('isCMCC', $script);
        $this->assertStringContainsString('isZTEGeneric', $script);
    }
}
