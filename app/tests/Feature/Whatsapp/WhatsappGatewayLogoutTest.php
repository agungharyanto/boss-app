<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\ResellerUserRole;
use App\Enums\ResellerUserStatus;
use App\Enums\WhatsappSessionStatus;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Branch migrasi-whatsmeow — tombol "Logout" per gateway (target EKSPLISIT
 * 'legacy'/'go', tidak pernah ambigu) + panel "Status Migrasi Gateway"
 * (baca live dari kedua gateway secara terpisah). Lihat
 * WhatsappSessionService::logout()/checkGatewayHealth() docblock.
 */
class WhatsappGatewayLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test',
            'services.whatsapp_gateway_go.url' => 'http://whatsapp-gateway-go-test',
        ]);
    }

    private function directSession(int $tenantId, WhatsappSessionStatus $status = WhatsappSessionStatus::Connected): WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'status' => $status,
            'phone_number' => '6281389014113',
        ]);
    }

    public function test_logout_posts_to_the_legacy_gateway_url_when_target_is_legacy(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session, 'legacy');

        $this->assertTrue($result);
        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->fresh()->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-test/sessions/direct/logout')
            && $request->method() === 'POST'
            && $request->hasHeader('X-Whatsapp-Signature'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-go-test'));
    }

    public function test_logout_posts_to_the_go_gateway_url_when_target_is_go(): void
    {
        Http::fake([
            'whatsapp-gateway-go-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session, 'go');

        $this->assertTrue($result);
        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->fresh()->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-go-test/sessions/direct/logout'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-test/sessions/direct/logout'));
    }

    public function test_logout_returns_false_and_does_not_change_status_on_gateway_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => false], 500),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session, 'legacy');

        $this->assertFalse($result);
        $this->assertSame(WhatsappSessionStatus::Connected, $session->fresh()->status);
    }

    public function test_check_gateway_health_reports_reachable_and_status_from_a_live_response(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/health' => Http::response([
                'success' => true, 'data' => ['status' => 'connected', 'phoneNumber' => '6281389014113'],
            ], 200),
        ]);

        $result = app(WhatsappSessionService::class)->checkGatewayHealth('legacy', 'direct');

        $this->assertTrue($result['reachable']);
        $this->assertSame('connected', $result['status']);
        $this->assertSame('6281389014113', $result['phone_number']);
        $this->assertNull($result['error']);
    }

    public function test_check_gateway_health_reports_unreachable_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $result = app(WhatsappSessionService::class)->checkGatewayHealth('go', 'direct');

        $this->assertFalse($result['reachable']);
        $this->assertNull($result['status']);
        $this->assertNotNull($result['error']);
    }

    public function test_check_gateway_health_reports_unreachable_when_url_not_configured(): void
    {
        config(['services.whatsapp_gateway_go.url' => null]);

        $result = app(WhatsappSessionService::class)->checkGatewayHealth('go', 'direct');

        $this->assertFalse($result['reachable']);
        $this->assertSame('URL gateway belum dikonfigurasi.', $result['error']);
    }

    public function test_admin_can_logout_a_session_from_a_specific_gateway_via_livewire(): void
    {
        Http::fake([
            'whatsapp-gateway-go-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('logoutFromGateway', $session->id, 'go')
            ->assertHasNoErrors();

        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-go-test/sessions/direct/logout'));
    }

    public function test_overview_tab_shows_both_gateway_statuses_separately(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/health' => Http::response(['success' => true, 'data' => ['status' => 'qr_pending']], 200),
            'whatsapp-gateway-go-test/sessions/direct/health' => Http::response(['success' => true, 'data' => null], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->directSession($tenant->id);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('setTab', 'overview')
            ->assertSee('Gateway Lama (Node, port 3000)')
            ->assertSee('Gateway Baru (Go, port 3001)')
            ->assertSee('Status Migrasi Gateway');
    }

    public function test_a_reseller_cannot_logout_the_direct_session(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id' => $owner->id,
            'role' => ResellerUserRole::Owner,
            'status' => ResellerUserStatus::Active,
        ]);
        $session = $this->directSession($tenant->id);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->call('logoutFromGateway', $session->id, 'legacy')
            ->assertForbidden();
    }
}
