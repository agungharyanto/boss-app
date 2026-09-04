<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappSessionStatus;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprint "whatsapp-gateway-reliability" LANGKAH 2 — alternatif "Kode
 * Pairing" (native Baileys requestPairingCode, TANPA scan QR).
 */
class WhatsappPairingCodeTest extends TestCase
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
            'status' => WhatsappSessionStatus::QrPending,
        ]);
    }

    public function test_service_posts_to_the_pair_endpoint_and_returns_the_code(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/pair' => Http::response(['success' => true, 'pairing_code' => 'ABCD-1234'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $code = app(WhatsappSessionService::class)->requestPairingCode($session, '6281234567890');

        $this->assertSame('ABCD-1234', $code);
        $this->assertSame(WhatsappSessionStatus::QrPending, $session->fresh()->status);
        $this->assertNull($session->fresh()->qr_code_data);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sessions/direct/pair')
                && $request->method() === 'POST'
                && $request['phone_number'] === '6281234567890'
                && $request->hasHeader('X-Whatsapp-Signature');
        });
    }

    public function test_service_returns_null_and_logs_on_gateway_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/pair' => Http::response(['success' => false, 'message' => 'already connected'], 500),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $code = app(WhatsappSessionService::class)->requestPairingCode($session, '6281234567890');

        $this->assertNull($code);
    }

    public function test_admin_can_request_a_pairing_code_via_the_livewire_component(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/pair' => Http::response(['success' => true, 'pairing_code' => 'WXYZ-9876'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('togglePairingMode', $session->id)
            ->assertSet('pairingModeSessionId', $session->id)
            ->set('pairingPhoneNumber', '6281234567890')
            ->call('requestPairingCode', $session->id)
            ->assertHasNoErrors()
            ->assertSet('pairingCodeResult', 'WXYZ-9876')
            ->assertSee('WXYZ-9876');
    }

    public function test_pairing_phone_number_is_required_and_validated(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('togglePairingMode', $session->id)
            ->set('pairingPhoneNumber', 'abc')
            ->call('requestPairingCode', $session->id)
            ->assertHasErrors('pairingPhoneNumber');

        Http::assertNothingSent();
    }

    public function test_a_plain_user_with_no_admin_or_reseller_access_cannot_open_the_page_at_all(): void
    {
        $tenant = Tenant::factory()->create();
        $plain = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->directSession($tenant->id);

        Livewire::actingAs($plain)
            ->test(WhatsappGatewayIndex::class)
            ->assertForbidden();
    }

    public function test_toggle_pairing_mode_clears_previous_result_when_switching_sessions(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/pair' => Http::response(['success' => true, 'pairing_code' => 'AAAA-1111'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id);

        $component = Livewire::actingAs($admin)->test(WhatsappGatewayIndex::class)
            ->call('togglePairingMode', $session->id)
            ->set('pairingPhoneNumber', '6281234567890')
            ->call('requestPairingCode', $session->id)
            ->assertSet('pairingCodeResult', 'AAAA-1111');

        // Toggling off (batal) clears the shown code.
        $component->call('togglePairingMode', $session->id)
            ->assertSet('pairingModeSessionId', null)
            ->assertSet('pairingCodeResult', null);
    }
}
