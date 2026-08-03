<?php

namespace Tests\Feature\Whatsapp;

use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsappSessionCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);

        Http::fake([
            'whatsapp-gateway-test/*' => Http::response(['qr_code_data' => null], 200),
        ]);
    }

    public function test_isp_admin_can_create_the_direct_session(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('createSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'status' => 'qr_pending',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/direct/qr'));
    }

    public function test_reseller_owner_can_create_their_own_session(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($owner)->test(WhatsappGatewayIndex::class)->assertOk();

        // Livewire component tests don't run the reseller.context HTTP
        // middleware — bind it directly, same technique as
        // ResellerTaxPolicyIndexLivewireTest.
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->call('createSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'status' => 'qr_pending',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), "/sessions/{$reseller->id}/qr"));
    }

    public function test_reseller_b_creating_a_session_never_gets_attributed_to_reseller_a(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappSession::factory()->forReseller($resellerA)->create();

        $ownerB = User::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB->users()->attach($ownerB->id, ['role' => 'owner', 'status' => 'active']);

        Livewire::actingAs($ownerB)->test(WhatsappGatewayIndex::class)->assertOk();
        app(ResellerContext::class)->set($resellerB);

        Livewire::actingAs($ownerB)
            ->test(WhatsappGatewayIndex::class)
            ->call('createSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_sessions', ['reseller_id' => $resellerB->id]);
        $this->assertDatabaseCount('whatsapp_sessions', 2);
        // Reseller A's pre-existing session must be untouched — still
        // exactly one row for A, not overwritten/duplicated.
        $this->assertSame(1, WhatsappSession::withoutGlobalScopes()->where('reseller_id', $resellerA->id)->count());
    }

    public function test_a_user_with_no_reseller_membership_and_no_admin_permission_cannot_create_a_session(): void
    {
        $tenant = Tenant::factory()->create();
        $plainUser = User::factory()->create(['tenant_id' => $tenant->id]);

        // No role, no reseller_users membership at all — mount() itself
        // should already reject via viewAny.
        Livewire::actingAs($plainUser)
            ->test(WhatsappGatewayIndex::class)
            ->assertForbidden();
    }

    public function test_admin_cannot_refresh_qr_for_a_resellers_own_session(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('super_admin');

        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $session = WhatsappSession::factory()->forReseller($reseller)->create();

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('refreshQr', $session->id)
            ->assertForbidden();
    }

    public function test_reseller_cannot_refresh_qr_for_the_direct_session(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $directSession = WhatsappSession::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        Livewire::actingAs($owner)->test(WhatsappGatewayIndex::class)->assertOk();
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->call('refreshQr', $directSession->id)
            ->assertForbidden();
    }
}
