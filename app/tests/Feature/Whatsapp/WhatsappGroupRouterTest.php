<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Tenant;
use App\Models\WhatsappGroupRoute;
use App\Models\WhatsappNotificationGroup;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\WhatsappGroupRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.13.4.4 — resolver routing grup + migrasi data wa_group_jid.
 */
class WhatsappGroupRouterTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORY = 'work_order_completed';

    private function router(): WhatsappGroupRouter
    {
        return app(WhatsappGroupRouter::class);
    }

    private function group(int $tenantId, array $overrides = []): WhatsappNotificationGroup
    {
        return WhatsappNotificationGroup::create(array_merge([
            'tenant_id' => $tenantId,
            'session_key' => 'direct',
            'jid' => '1203'.fake()->unique()->numerify('######').'@g.us',
            'label' => 'Grup',
            'is_default' => false,
            'is_active' => true,
        ], $overrides));
    }

    public function test_an_active_route_resolves_to_the_routed_group(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);
        $routed = $this->group($tenant->id);
        WhatsappGroupRoute::create([
            'tenant_id' => $tenant->id,
            'category' => self::CATEGORY,
            'group_id' => $routed->id,
        ]);

        $this->assertSame($routed->id, $this->router()->resolve($tenant->id, self::CATEGORY)?->id);
        $this->assertNotSame($default->id, $this->router()->resolve($tenant->id, self::CATEGORY)?->id);
    }

    public function test_a_route_to_an_inactive_group_falls_back_to_default(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);
        $inactive = $this->group($tenant->id, ['is_active' => false]);
        WhatsappGroupRoute::create([
            'tenant_id' => $tenant->id,
            'category' => self::CATEGORY,
            'group_id' => $inactive->id,
        ]);

        $this->assertSame($default->id, $this->router()->resolve($tenant->id, self::CATEGORY)?->id);
    }

    public function test_no_route_falls_back_to_default(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);

        $this->assertSame($default->id, $this->router()->resolve($tenant->id, self::CATEGORY)?->id);
    }

    public function test_a_null_group_route_falls_back_to_default(): void
    {
        $tenant = Tenant::factory()->create();
        $default = $this->group($tenant->id, ['is_default' => true]);
        WhatsappGroupRoute::create([
            'tenant_id' => $tenant->id,
            'category' => self::CATEGORY,
            'group_id' => null,
        ]);

        $this->assertSame($default->id, $this->router()->resolve($tenant->id, self::CATEGORY)?->id);
    }

    public function test_no_default_resolves_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        // Ada grup tapi bukan default — resolver tetap null (skip diam-diam).
        $this->group($tenant->id, ['is_default' => false]);

        $this->assertNull($this->router()->resolve($tenant->id, self::CATEGORY));
    }

    public function test_an_inactive_default_resolves_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        $this->group($tenant->id, ['is_default' => true, 'is_active' => false]);

        $this->assertNull($this->router()->resolve($tenant->id, self::CATEGORY));
    }

    public function test_resolution_is_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->group($tenantA->id, ['is_default' => true]);

        // Tenant B tidak punya grup sama sekali → null, tidak "meminjam" milik A.
        $this->assertNull($this->router()->resolve($tenantB->id, self::CATEGORY));
    }

    // ── Migrasi data wa_group_jid → grup default ─────────────────────────

    private function dataMigration(): object
    {
        return require database_path('migrations/2026_11_03_110100_migrate_wa_group_jid_to_default_notification_group.php');
    }

    public function test_data_migration_creates_one_default_group_from_wa_group_jid(): void
    {
        $tenant = Tenant::factory()->create();
        $settings = WorkOrderDispatchSettings::forTenant($tenant->id);
        $settings->update(['wa_group_jid' => '1203legacy@g.us', 'wa_group_name' => 'Grup Lama']);

        $this->dataMigration()->up();

        $groups = WhatsappNotificationGroup::where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $groups);
        $this->assertSame('1203legacy@g.us', $groups->first()->jid);
        $this->assertSame('Grup Lama', $groups->first()->label);
        $this->assertTrue($groups->first()->is_default);
        $this->assertSame('direct', $groups->first()->session_key);

        // Perilaku sama dengan sebelum migrasi: pesan grup kategori apa pun
        // jatuh ke grup ini (routing kosong → default).
        $this->assertSame(
            $groups->first()->id,
            $this->router()->resolve($tenant->id, self::CATEGORY)?->id,
        );
    }

    public function test_data_migration_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id)->update(['wa_group_jid' => '1203legacy@g.us']);

        $migration = $this->dataMigration();
        $migration->up();
        $migration->up(); // run kedua — TIDAK membuat duplikat / default kedua.

        $this->assertSame(1, WhatsappNotificationGroup::where('tenant_id', $tenant->id)->count());
        $this->assertSame('Grup Utama', WhatsappNotificationGroup::where('tenant_id', $tenant->id)->first()->label);
    }

    public function test_data_migration_skips_tenant_with_empty_wa_group_jid(): void
    {
        $tenant = Tenant::factory()->create();
        WorkOrderDispatchSettings::forTenant($tenant->id); // wa_group_jid null default

        $this->dataMigration()->up();

        $this->assertSame(0, WhatsappNotificationGroup::where('tenant_id', $tenant->id)->count());
    }
}
