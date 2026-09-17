<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappSessionStatus;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.26.4 — WhatsappSessionService::listGroups(). Pola sama persis
 * WhatsappPairingCodeTest (Http::fake per test, domain palsu di setUp()).
 */
class WhatsappGroupListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);
    }

    private function directSession(int $tenantId): WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'status' => WhatsappSessionStatus::Connected,
        ]);
    }

    public function test_service_returns_groups_from_the_gateway(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response([
                'success' => true,
                'groups' => [
                    ['jid' => '1203aaa@g.us', 'name' => 'Grup Notifikasi Teknisi'],
                    ['jid' => '1203bbb@g.us', 'name' => 'Grup Lain'],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $groups = app(WhatsappSessionService::class)->listGroups($session);

        $this->assertCount(2, $groups);
        $this->assertSame('1203aaa@g.us', $groups[0]['jid']);
        $this->assertSame('Grup Notifikasi Teknisi', $groups[0]['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sessions/direct/groups')
                && $request->method() === 'GET'
                && $request->hasHeader('X-Whatsapp-Signature');
        });
    }

    public function test_service_returns_empty_array_on_gateway_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response(['success' => false, 'message' => 'session not connected'], 500),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $groups = app(WhatsappSessionService::class)->listGroups($session);

        $this->assertSame([], $groups);
    }

    public function test_service_returns_empty_array_when_gateway_url_not_configured(): void
    {
        config(['services.whatsapp_gateway.url' => null]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $groups = app(WhatsappSessionService::class)->listGroups($session);

        $this->assertSame([], $groups);
    }

    public function test_service_returns_empty_array_when_bot_has_no_groups(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/groups' => Http::response(['success' => true, 'groups' => []], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $groups = app(WhatsappSessionService::class)->listGroups($session);

        $this->assertSame([], $groups);
    }

    /**
     * Reseller session (bukan "direct") — dipakai key numerik resellerId,
     * bukan sekadar path yang selalu sama. Endpoint groups memakai
     * sessionKey() yang sama seperti endpoint lain, tidak ada logic baru.
     */
    public function test_service_uses_the_reseller_session_key_for_a_reseller_session(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        Http::fake([
            "whatsapp-gateway-test/sessions/{$reseller->id}/groups" => Http::response([
                'success' => true,
                'groups' => [['jid' => '1203ccc@g.us', 'name' => 'Grup Reseller']],
            ], 200),
        ]);

        $session = WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'status' => WhatsappSessionStatus::Connected,
        ]);

        $groups = app(WhatsappSessionService::class)->listGroups($session);

        $this->assertCount(1, $groups);
        $this->assertSame('Grup Reseller', $groups[0]['name']);
    }
}
