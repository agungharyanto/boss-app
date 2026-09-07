<?php

namespace Tests\Feature\Network;

use App\Enums\MikrotikSyncStatus;
use App\Jobs\SyncRemoteWanConfigToGenieAcsJob;
use App\Livewire\Network\RemoteConfigSettings;
use App\Models\RemoteWanConfig;
use App\Models\User;
use App\Services\Network\GenieAcsPresetService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RemoteConfigSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // inspectAutoWanState() dipanggil di mount() — fake genieacs-nbi.
        Http::fake([
            'genieacs-nbi:7557/*' => Http::response([], 200),
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        Livewire::actingAs($this->user('customer_service'))
            ->test(RemoteConfigSettings::class)
            ->assertForbidden();
    }

    public function test_admin_and_noc_can_view(): void
    {
        foreach (['superadmin', 'administrator', 'noc'] as $role) {
            Livewire::actingAs($this->user($role))
                ->test(RemoteConfigSettings::class)
                ->assertOk()
                ->assertSee('Konfig Remote');
        }
    }

    public function test_save_persists_values_sets_pending_and_dispatches_the_sync_job(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user('superadmin'))
            ->test(RemoteConfigSettings::class)
            ->set('enabled', true)
            ->set('wan1_vlan', 1500)
            ->set('wan1_pppoe_username', 'boss-default')
            ->set('wan2_enabled', true)
            ->set('wan2_vlan', 1250)
            ->call('save')
            ->assertHasNoErrors();

        $config = RemoteWanConfig::current();
        $this->assertTrue($config->enabled);
        $this->assertSame(1500, $config->wan1_vlan);
        $this->assertSame('boss-default', $config->wan1_pppoe_username);
        $this->assertTrue($config->wan2_enabled);
        $this->assertSame(1250, $config->wan2_vlan);
        $this->assertSame(MikrotikSyncStatus::Pending, $config->genieacs_sync_status);

        Bus::assertDispatched(SyncRemoteWanConfigToGenieAcsJob::class);
    }

    public function test_vlan_out_of_range_is_rejected(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user('superadmin'))
            ->test(RemoteConfigSettings::class)
            ->set('wan1_vlan', 5000)
            ->call('save')
            ->assertHasErrors(['wan1_vlan']);

        Bus::assertNotDispatched(SyncRemoteWanConfigToGenieAcsJob::class);
    }

    public function test_empty_pppoe_username_is_rejected(): void
    {
        Livewire::actingAs($this->user('superadmin'))
            ->test(RemoteConfigSettings::class)
            ->set('wan1_pppoe_username', '')
            ->call('save')
            ->assertHasErrors(['wan1_pppoe_username']);
    }

    public function test_a_view_only_user_cannot_save(): void
    {
        // Buat role kustom: hanya remote_config.view.
        $u = User::factory()->create();
        $u->givePermissionTo('remote_config.view');

        Livewire::actingAs($u)
            ->test(RemoteConfigSettings::class)
            ->set('enabled', true)
            ->call('save')
            ->assertForbidden();

        $this->assertFalse(RemoteWanConfig::current()->enabled);
    }

    public function test_resync_re_dispatches_the_job(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user('noc'))
            ->test(RemoteConfigSettings::class)
            ->call('resync')
            ->assertHasNoErrors();

        Bus::assertDispatched(SyncRemoteWanConfigToGenieAcsJob::class);
        $this->assertSame(MikrotikSyncStatus::Pending, RemoteWanConfig::current()->genieacs_sync_status);
    }

    // ── Job ────────────────────────────────────────────────────────────

    public function test_sync_job_calls_the_preset_service_and_marks_synced(): void
    {
        RemoteWanConfig::current()->update(['enabled' => true, 'wan1_vlan' => 1000]);

        $spy = \Mockery::mock(GenieAcsPresetService::class);
        $spy->shouldReceive('syncAutoWanConfig')->once();
        $this->app->instance(GenieAcsPresetService::class, $spy);

        (new SyncRemoteWanConfigToGenieAcsJob)->handle(app(GenieAcsPresetService::class));

        $this->assertSame(MikrotikSyncStatus::Synced, RemoteWanConfig::current()->genieacs_sync_status);
    }

    public function test_sync_job_marks_failed_on_the_final_attempt(): void
    {
        $throwing = \Mockery::mock(GenieAcsPresetService::class);
        $throwing->shouldReceive('syncAutoWanConfig')->andThrow(new \RuntimeException('genieacs-nbi unreachable'));

        $job = \Mockery::mock(SyncRemoteWanConfigToGenieAcsJob::class.'[attempts]')->makePartial();
        $job->shouldReceive('attempts')->andReturn(3);

        $job->handle($throwing);

        $config = RemoteWanConfig::current();
        $this->assertSame(MikrotikSyncStatus::Failed, $config->genieacs_sync_status);
        $this->assertStringContainsString('unreachable', $config->genieacs_sync_error);
    }
}
