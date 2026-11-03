<?php

namespace Tests\Feature\Settings;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Livewire\Settings\WorkOrderDispatchSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappGroupRoute;
use App\Models\WhatsappNotificationGroup;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Komunikasi > Konfig WA Gateway". `work_orders.manage` (tier-admin).
 *
 * v0.13.4.4 — field tunggal "Pilih Grup" diganti manajemen multi-grup +
 * routing per kategori. Timing dispatch/reminder tetap diuji; grup/routing
 * dikelompokkan di bawah.
 */
class WorkOrderDispatchSettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

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

    private function fakeGroups(array $groups): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => $groups,
            ], 200),
        ]);
    }

    private function group(int $tenantId, array $overrides = []): WhatsappNotificationGroup
    {
        return WhatsappNotificationGroup::create(array_merge([
            'tenant_id' => $tenantId,
            'session_key' => 'direct',
            'jid' => '1203'.fake()->unique()->numerify('######').'@g.us',
            'label' => 'Grup '.fake()->word(),
            'is_default' => false,
            'is_active' => true,
        ], $overrides));
    }

    // ── Timing ──────────────────────────────────────────────────────────

    public function test_form_loads_the_defaults_for_a_fresh_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('dispatchOffsetHours', 2)
            ->assertSet('reminderTime', '08:00')
            ->assertSet('commandIntervalMinutes', 15);
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
        $this->assertSame(180, $settings->dispatch_offset_minutes);
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

    public function test_command_interval_minutes_must_be_between_one_and_sixty(): void
    {
        $tenant = Tenant::factory()->create();

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

    // ── Daftar grup bot (fetch/cache) ────────────────────────────────────

    public function test_shows_a_clear_message_when_session_not_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id, WhatsappSessionStatus::QrPending);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('sessionConnected', false)
            ->assertSee('belum terhubung');
    }

    public function test_bot_group_list_is_populated_from_the_gateway_when_session_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([
            ['jid' => '1203aaa@g.us', 'name' => 'Grup Notifikasi Teknisi'],
            ['jid' => '1203bbb@g.us', 'name' => 'Grup Lain'],
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSet('sessionConnected', true)
            ->assertSet('groupsLoadFailed', false)
            ->assertSee('Grup Notifikasi Teknisi')
            ->assertSee('Grup Lain');
    }

    public function test_bot_group_list_is_cached_not_fetched_on_every_render(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup A']]);

        $component = Livewire::actingAs($this->admin($tenant))->test(WorkOrderDispatchSettings::class);
        $component->set('dispatchOffsetHours', 4);

        Http::assertSentCount(1);
    }

    public function test_reload_button_bypasses_the_cache(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup A']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('reloadGroups');

        Http::assertSentCount(2);
    }

    public function test_bot_group_list_is_isolated_per_tenant_cache_key(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Cache::put("whatsapp:groups:tenant:{$tenantA->id}", [['jid' => '1203aaa@g.us', 'name' => 'Grup A']], now()->addMinutes(15));
        $this->directSession($tenantB->id);
        $this->fakeGroups([['jid' => '1203bbb@g.us', 'name' => 'Grup B']]);

        Livewire::actingAs($this->admin($tenantB))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSee('Grup B')
            ->assertDontSee('Grup A');
    }

    // ── Tambah grup ──────────────────────────────────────────────────────

    public function test_adding_the_first_group_makes_it_default(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup A']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203aaa@g.us')
            ->set('newGroupLabel', 'Support Teknisi')
            ->call('addGroup')
            ->assertHasNoErrors();

        $group = WhatsappNotificationGroup::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('1203aaa@g.us', $group->jid);
        $this->assertSame('Support Teknisi', $group->label);
        $this->assertTrue($group->is_default);
        $this->assertSame('direct', $group->session_key);
    }

    public function test_adding_a_second_group_is_not_default(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->group($tenant->id, ['jid' => '1203first@g.us', 'is_default' => true]);
        $this->fakeGroups([['jid' => '1203second@g.us', 'name' => 'Kedua']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203second@g.us')
            ->set('newGroupLabel', 'Kedua')
            ->call('addGroup')
            ->assertHasNoErrors();

        $second = WhatsappNotificationGroup::where('jid', '1203second@g.us')->firstOrFail();
        $this->assertFalse($second->is_default);
        $this->assertSame(1, WhatsappNotificationGroup::where('tenant_id', $tenant->id)->where('is_default', true)->count());
    }

    public function test_adding_a_jid_not_in_the_bot_list_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203real@g.us', 'name' => 'Nyata']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203palsu@g.us')
            ->set('newGroupLabel', 'Palsu')
            ->call('addGroup')
            ->assertHasErrors('newGroupJid');

        $this->assertSame(0, WhatsappNotificationGroup::where('tenant_id', $tenant->id)->count());
    }

    // ── Default / aktif / hapus ─────────────────────────────────────────

    public function test_set_default_moves_the_default_and_activates_the_group(): void
    {
        $tenant = Tenant::factory()->create();
        $a = $this->group($tenant->id, ['is_default' => true]);
        $b = $this->group($tenant->id, ['is_default' => false, 'is_active' => false]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('setDefault', $b->id);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_active);
    }

    public function test_cannot_delete_the_default_while_other_groups_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);
        $this->group($tenant->id);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('deleteGroup', $default->id);

        $this->assertNotNull($default->fresh());
    }

    public function test_can_delete_the_only_group_even_if_default(): void
    {
        $tenant = Tenant::factory()->create();
        $only = $this->group($tenant->id, ['is_default' => true]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('deleteGroup', $only->id);

        $this->assertNull($only->fresh());
    }

    public function test_cannot_deactivate_the_default_while_other_groups_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true, 'is_active' => true]);
        $this->group($tenant->id);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('toggleActive', $default->id);

        $this->assertTrue($default->fresh()->is_active);
    }

    public function test_save_label_updates_the_group_label(): void
    {
        $tenant = Tenant::factory()->create();
        $group = $this->group($tenant->id, ['label' => 'Lama']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set("groupLabels.{$group->id}", 'Baru')
            ->call('saveLabel', $group->id);

        $this->assertSame('Baru', $group->fresh()->label);
    }

    public function test_a_group_from_another_tenant_cannot_be_mutated(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreign = $this->group($otherTenant->id, ['is_default' => true, 'label' => 'Asing']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('deleteGroup', $foreign->id);

        $this->assertNotNull($foreign->fresh(), 'grup tenant lain tidak boleh terhapus');
    }

    // ── Routing (tidak auto-save; butuh tombol "Simpan Routing") ─────────

    public function test_changing_the_dropdown_does_not_save_routing(): void
    {
        $tenant = Tenant::factory()->create();
        $group = $this->group($tenant->id, ['is_default' => true]);
        $cat = WhatsappEventType::WorkOrderCompleted->value;

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('routeGroupId.'.$cat, (string) $group->id);

        // Dropdown hanya mengubah state lokal — DB belum tersentuh.
        $this->assertDatabaseMissing('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => $cat,
        ]);
        $this->assertTrue($component->instance()->routingDirty());
    }

    public function test_save_routing_persists_the_selection_and_clears_dirty(): void
    {
        $tenant = Tenant::factory()->create();
        $group = $this->group($tenant->id, ['is_default' => true]);
        $cat = WhatsappEventType::WorkOrderCompleted->value;

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('routeGroupId.'.$cat, (string) $group->id)
            ->call('saveRouting')
            ->assertHasNoErrors()
            ->assertSee('Routing disimpan.');

        $this->assertDatabaseHas('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => $cat,
            'group_id' => $group->id,
        ]);
        $this->assertFalse($component->instance()->routingDirty());
    }

    public function test_save_routing_with_empty_selection_stores_null_group(): void
    {
        $tenant = Tenant::factory()->create();
        $group = $this->group($tenant->id, ['is_default' => true]);
        $cat = WhatsappEventType::WorkOrderCompleted->value;
        WhatsappGroupRoute::create([
            'tenant_id' => $tenant->id,
            'category' => $cat,
            'group_id' => $group->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('routeGroupId.'.$cat, '')
            ->call('saveRouting')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => $cat,
            'group_id' => null,
        ]);
    }

    public function test_save_routing_rejects_an_inactive_group(): void
    {
        $tenant = Tenant::factory()->create();
        $this->group($tenant->id, ['is_default' => true]);
        $inactive = $this->group($tenant->id, ['is_active' => false]);
        $cat = WhatsappEventType::WorkOrderCompleted->value;

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('routeGroupId.'.$cat, (string) $inactive->id)
            ->call('saveRouting')
            ->assertHasErrors('routeGroupId.'.$cat);

        $this->assertDatabaseMissing('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => $cat,
        ]);
    }

    public function test_save_routing_rejects_a_group_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreign = $this->group($otherTenant->id, ['is_default' => true]);
        $cat = WhatsappEventType::WorkOrderCompleted->value;

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('routeGroupId.'.$cat, (string) $foreign->id)
            ->call('saveRouting')
            ->assertHasErrors('routeGroupId.'.$cat);

        $this->assertDatabaseMissing('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => $cat,
            'group_id' => $foreign->id,
        ]);
    }

    // ── Flash konfirmasi & pesan galat ───────────────────────────────────

    public function test_add_and_save_label_flash_confirmations(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup A']]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203aaa@g.us')
            ->set('newGroupLabel', 'Grup A')
            ->call('addGroup')
            ->assertSee('Grup ditambahkan.');

        $g = WhatsappNotificationGroup::where('tenant_id', $tenant->id)->firstOrFail();

        $component->set("groupLabels.{$g->id}", 'Grup A Baru')
            ->call('saveLabel', $g->id)
            ->assertSee('Label disimpan.');
    }

    public function test_toggle_active_flashes_specific_messages(): void
    {
        $tenant = Tenant::factory()->create();
        $this->group($tenant->id, ['is_default' => true]);
        $other = $this->group($tenant->id, ['is_active' => true]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('toggleActive', $other->id)
            ->assertSee('Grup dinonaktifkan.')
            ->call('toggleActive', $other->id)
            ->assertSee('Grup diaktifkan.');
    }

    public function test_set_default_flashes_the_new_default_label(): void
    {
        $tenant = Tenant::factory()->create();
        $this->group($tenant->id, ['is_default' => true]);
        $b = $this->group($tenant->id, ['label' => 'Grup Baru']);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('setDefault', $b->id)
            ->assertSee('Default diubah ke "Grup Baru".');
    }

    public function test_adding_without_selecting_a_group_shows_the_new_message(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup A']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '')
            ->set('newGroupLabel', '')
            ->call('addGroup')
            ->assertHasErrors('newGroupJid')
            ->assertSee('Pilih grup dari dropdown dulu.');
    }

    // ── Poles label grup (v0.13.4.4) ─────────────────────────────────────

    public function test_selecting_a_group_prefills_the_label_with_its_real_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([['jid' => '1203aaa@g.us', 'name' => 'Grup Teknisi Asli']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203aaa@g.us')
            ->assertSet('newGroupLabel', 'Grup Teknisi Asli');
    }

    public function test_a_manually_typed_label_is_not_overwritten_when_changing_the_group(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->fakeGroups([
            ['jid' => '1203aaa@g.us', 'name' => 'Grup A'],
            ['jid' => '1203bbb@g.us', 'name' => 'Grup B'],
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->set('newGroupJid', '1203aaa@g.us')
            ->assertSet('newGroupLabel', 'Grup A')
            ->set('newGroupLabel', 'Label Ketik Manual')
            ->set('newGroupJid', '1203bbb@g.us')
            ->assertSet('newGroupLabel', 'Label Ketik Manual');
    }

    public function test_group_row_shows_the_real_whatsapp_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->group($tenant->id, ['jid' => '1203real@g.us', 'label' => 'Label Berbeda', 'is_default' => true]);
        $this->fakeGroups([['jid' => '1203real@g.us', 'name' => 'Nama Asli WA']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSee('Nama di WhatsApp:')
            ->assertSee('Nama Asli WA');
    }

    public function test_group_row_shows_fallback_when_jid_not_in_bot_list(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);
        $this->group($tenant->id, ['jid' => '1203gone@g.us', 'is_default' => true]);
        $this->fakeGroups([['jid' => '1203other@g.us', 'name' => 'Lain']]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->assertSee('(tidak ada di daftar grup bot — muat ulang)');
    }

    public function test_deleting_a_group_nulls_routes_pointing_to_it(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);
        $routed = $this->group($tenant->id);
        WhatsappGroupRoute::create([
            'tenant_id' => $tenant->id,
            'category' => WhatsappEventType::WorkOrderCompleted->value,
            'group_id' => $routed->id,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WorkOrderDispatchSettings::class)
            ->call('deleteGroup', $routed->id);

        $this->assertNull($routed->fresh());
        $this->assertDatabaseHas('whatsapp_group_routes', [
            'tenant_id' => $tenant->id,
            'category' => WhatsappEventType::WorkOrderCompleted->value,
            'group_id' => null,
        ]);
    }
}
