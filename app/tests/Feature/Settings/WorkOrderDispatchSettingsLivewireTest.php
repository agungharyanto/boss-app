<?php

namespace Tests\Feature\Settings;

use App\Enums\WhatsappSessionStatus;
use App\Livewire\Settings\WorkOrderDispatchSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.26.2 — "Komunikasi > Konfig WA Gateway". `work_orders.manage`
 * (tier-admin, sudah ada) — tidak ada permission baru untuk halaman ini,
 * dikonfirmasi Langkah 0.
 *
 * v0.26.4 — dropdown "Pilih Grup WhatsApp" menggantikan 2 field manual
 * lama. Test terkait dropdown (state kosong, populate dari gateway, cache,
 * tombol reload) dikelompokkan terpisah di bawah.
 */
class WorkOrderDispatchSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // phpunit.xml mengosongkan WHATSAPP_GATEWAY_URL secara default
        // supaya test yang lupa men-fake tidak diam-diam menembak gateway
        // asli — domain palsu ini WAJIB diset di sini juga, sama disiplin
        // WhatsappPairingCodeTest dkk.
        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function directSession(int $tenantId, WhatsappSessionStatus $status = WhatsappSessionStatus::Connected): WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'status' => $status,
        ]);
    }

    public function test_form_loads_the_defaults_for_a_fresh_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('dispatchOffsetHours', 2)
            ->assertSet('reminderTime', '08:00')
            ->assertSet('commandIntervalMinutes', 15)
            ->assertSet('waGroupJid', null)
            ->assertSet('waGroupName', null);
    }

    public function test_saving_valid_values_persists_them_converted_to_minutes(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 3)
            ->set('reminderTime', '09:30')
            ->set('commandIntervalMinutes', 20)
            ->call('save')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettingsModel::forTenant($tenant->id);
        $this->assertSame(180, $settings->dispatch_offset_minutes); // 3 jam -> 180 menit
        $this->assertSame('09:30', $settings->reminder_time);
        $this->assertSame(20, $settings->command_interval_minutes);
    }

    public function test_dispatch_offset_hours_must_be_at_least_one(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 0)
            ->call('save')
            ->assertHasErrors(['dispatchOffsetHours' => 'min']);
    }

    public function test_reminder_time_must_be_a_valid_time_format(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('reminderTime', 'bukan-jam')
            ->call('save')
            ->assertHasErrors(['reminderTime' => 'date_format']);
    }

    public function test_command_interval_minutes_must_be_between_one_and_sixty(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('commandIntervalMinutes', 0)
            ->call('save')
            ->assertHasErrors(['commandIntervalMinutes' => 'min']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('commandIntervalMinutes', 61)
            ->call('save')
            ->assertHasErrors(['commandIntervalMinutes' => 'max']);
    }

    public function test_a_user_without_work_orders_manage_permission_cannot_access_the_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('customer_service');

        Livewire::actingAs($user)->test(WorkOrderDispatchSettings::class)->assertForbidden();
    }

    public function test_settings_are_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenantA))
            ->test(WorkOrderDispatchSettings::class)
            ->set('dispatchOffsetHours', 5)
            ->call('save');

        $this->assertSame(300, WorkOrderDispatchSettingsModel::forTenant($tenantA->id)->dispatch_offset_minutes);
        $this->assertSame(120, WorkOrderDispatchSettingsModel::forTenant($tenantB->id)->dispatch_offset_minutes);
    }

    // ── v0.26.4 — dropdown "Pilih Grup WhatsApp" ────────────────────────

    public function test_dropdown_is_disabled_with_a_clear_message_when_session_not_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id, WhatsappSessionStatus::QrPending);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('sessionConnected', false)
            ->assertSee('Sesi WhatsApp belum terhubung');
    }

    public function test_dropdown_shows_a_clear_message_when_no_session_row_exists_at_all(): void
    {
        $tenant = Tenant::factory()->create();
        // Sengaja TIDAK membuat WhatsappSession sama sekali.

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('sessionConnected', false)
            ->assertSee('Sesi WhatsApp belum terhubung');
    }

    public function test_dropdown_is_populated_from_the_gateway_when_session_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [
                    ['jid' => '1203aaa@g.us', 'name' => 'Grup Notifikasi Teknisi'],
                    ['jid' => '1203bbb@g.us', 'name' => 'Grup Lain'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('sessionConnected', true)
            ->assertSet('groupsLoadFailed', false)
            ->assertSee('Grup Notifikasi Teknisi')
            ->assertSee('Grup Lain');
    }

    public function test_selecting_a_group_fills_both_jid_and_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [
                    ['jid' => '1203aaa@g.us', 'name' => 'Grup Notifikasi Teknisi'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('waGroupJid', '1203aaa@g.us')
            ->assertSet('waGroupName', 'Grup Notifikasi Teknisi')
            ->call('save')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettingsModel::forTenant($tenant->id);
        $this->assertSame('1203aaa@g.us', $settings->wa_group_jid);
        $this->assertSame('Grup Notifikasi Teknisi', $settings->wa_group_name);
    }

    public function test_clearing_the_selection_clears_both_fields_on_save(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettingsModel::forTenant($tenant->id)->update([
            'wa_group_jid' => '1203aaa@g.us',
            'wa_group_name' => 'Grup Lama',
        ]);
        $this->directSession($tenant->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response(['success' => true, 'groups' => []], 200),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('waGroupJid', '1203aaa@g.us')
            ->set('waGroupJid', '')
            ->assertSet('waGroupName', null)
            ->call('save')
            ->assertHasNoErrors();

        $settings = WorkOrderDispatchSettingsModel::forTenant($tenant->id);
        $this->assertNull($settings->wa_group_jid);
        $this->assertNull($settings->wa_group_name);
    }

    public function test_group_list_is_cached_not_fetched_on_every_render(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [['jid' => '1203aaa@g.us', 'name' => 'Grup A']],
            ], 200),
        ]);

        $component = Livewire::actingAs($this->admin($tenant))->test(WorkOrderDispatchSettings::class);
        // Sebuah re-render (mis. set field lain) TIDAK memicu fetch baru —
        // masih dalam window cache 15 menit.
        $component->set('dispatchOffsetHours', 4);

        Http::assertSentCount(1);
    }

    public function test_reload_button_bypasses_the_cache(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [['jid' => '1203aaa@g.us', 'name' => 'Grup A']],
            ], 200),
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('reloadGroups');

        Http::assertSentCount(2); // mount() + reloadGroups() — cache genuinely di-bypass.
    }

    public function test_group_list_is_isolated_per_tenant_cache_key(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Cache::put("whatsapp:groups:tenant:{$tenantA->id}", [['jid' => '1203aaa@g.us', 'name' => 'Grup A']], now()->addMinutes(15));
        $this->directSession($tenantB->id);

        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [['jid' => '1203bbb@g.us', 'name' => 'Grup B']],
            ], 200),
        ]);

        // Tenant B genuinely fetch dari gateway (cache-nya sendiri kosong),
        // tidak "kebocoran" hasil cache tenant A.
        Livewire::actingAs($this->admin($tenantB))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSee('Grup B')
            ->assertDontSee('Grup A');
    }
}
