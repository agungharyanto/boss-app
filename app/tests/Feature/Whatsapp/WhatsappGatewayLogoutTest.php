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
use App\Support\ResellerContext;
use App\Support\WhatsappHmac;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Migrasi whatsmeow selesai — gateway Node/Baileys resmi pensiun, hanya
 * ada 1 gateway (Go/whatsmeow, `services.whatsapp_gateway.url`). Method
 * dual-target era migrasi (`logout($session, $target)`,
 * `checkGatewayHealth($target, $key)`, `baseUrlFor()`) sudah disederhanakan
 * — `logout()` sekarang single-argument, `checkGatewayHealth()` dihapus
 * (tidak ada lagi caller produksi setelah panel "Status Migrasi Gateway"
 * dihapus).
 */
class WhatsappGatewayLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);
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

    private function resellerSession(Reseller $reseller, WhatsappSessionStatus $status = WhatsappSessionStatus::Connected, string $phoneNumber = '6281234567890'): WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $reseller->tenant_id,
            'reseller_id' => $reseller->id,
            'status' => $status,
            'phone_number' => $phoneNumber,
        ]);
    }

    private function resellerOwner(Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $reseller->tenant_id]);
        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id' => $owner->id,
            'role' => ResellerUserRole::Owner,
            'status' => ResellerUserStatus::Active,
        ]);

        return $owner;
    }

    public function test_logout_posts_to_the_gateway_url(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session);

        $this->assertTrue($result);
        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->fresh()->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-test/sessions/direct/logout')
            && $request->method() === 'POST'
            && $request->hasHeader('X-Whatsapp-Signature'));
    }

    /**
     * Regression guard untuk bug NYATA yang sempat lolos (era migrasi
     * whatsmeow, sesi "masih gagal berkali-kali") — Http::fake() TIDAK
     * memverifikasi kecocokan HMAC seperti gateway asli, jadi test lain di
     * file ini yang cuma cek hasSent()/hasHeader() TIDAK PERNAH menangkap
     * signature yang di-sign atas string SALAH. Test ini memverifikasi
     * body yang BENAR-BENAR terkirim (bukan diasumsikan) genuinely string
     * kosong DAN signature-nya genuinely WhatsappHmac::sign('', $timestamp)
     * atas timestamp yang SAMA persis dengan header yang dikirim — persis
     * apa yang verifyHmac gateway hitung ulang di sisi penerima.
     */
    public function test_logout_signs_the_exact_empty_body_it_actually_sends(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        app(WhatsappSessionService::class)->logout($session);

        $hmac = app(WhatsappHmac::class);

        Http::assertSent(function ($request) use ($hmac) {
            $timestamp = (int) $request->header('X-Whatsapp-Timestamp')[0];
            $expectedSignature = $hmac->sign($request->body(), $timestamp);

            return $request->body() === ''
                && $request->header('X-Whatsapp-Signature')[0] === $expectedSignature;
        });
    }

    public function test_logout_returns_false_and_does_not_change_status_on_gateway_failure(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => false], 500),
        ]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session);

        $this->assertFalse($result);
        $this->assertSame(WhatsappSessionStatus::Connected, $session->fresh()->status);
    }

    public function test_logout_returns_false_when_url_not_configured(): void
    {
        config(['services.whatsapp_gateway.url' => null]);

        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);

        $result = app(WhatsappSessionService::class)->logout($session);

        $this->assertFalse($result);
    }

    /**
     * Regression guard — Blade view tidak menampilkan panel dual-gateway
     * ("Status Migrasi Gateway") peninggalan era migrasi. Halaman tampil
     * sesederhana sebelum migrasi: 1 baris status polos, tanpa pembedaan
     * gateway.
     */
    public function test_overview_tab_shows_a_single_plain_status_line(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->directSession($tenant->id, WhatsappSessionStatus::LoggedOut);

        $html = Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('setTab', 'overview')
            ->html();

        $this->assertStringNotContainsString('Status Migrasi Gateway', $html);
        $this->assertStringNotContainsString('Gateway Lama', $html);
        $this->assertStringNotContainsString('Gateway Baru', $html);
        $this->assertStringNotContainsString('logoutFromGateway', $html);

        $this->assertFalse(method_exists(WhatsappGatewayIndex::class, 'logoutFromGateway'));
        $this->assertFalse(method_exists(WhatsappSessionService::class, 'checkGatewayHealth'));
    }

    public function test_logout_button_only_shows_when_the_session_is_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $connected = $this->directSession($tenant->id, WhatsappSessionStatus::Connected);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('setTab', 'overview')
            ->assertSee('Logout')
            ->assertSeeHtml("logout({$connected->id})");
    }

    public function test_logout_button_is_hidden_when_the_session_is_not_connected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id, WhatsappSessionStatus::LoggedOut);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('setTab', 'overview')
            ->assertDontSeeHtml("logout({$session->id})");
    }

    public function test_admin_can_logout_the_connected_session_via_the_simple_button(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/direct/logout' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $session = $this->directSession($tenant->id);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->call('logout', $session->id)
            ->assertHasNoErrors();

        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp-gateway-test/sessions/direct/logout'));
    }

    public function test_a_reseller_cannot_logout_the_direct_session_via_the_simple_button(): void
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
            ->call('logout', $session->id)
            ->assertForbidden();
    }

    // --- tombol Logout di tab Konfigurasi (reseller) — 2026-09-15 ---

    public function test_reseller_sees_a_logout_button_in_konfigurasi_tab_when_connected(): void
    {
        $reseller = Reseller::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $owner = $this->resellerOwner($reseller);
        $session = $this->resellerSession($reseller);
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'konfigurasi')
            ->assertSee('Logout')
            ->assertSeeHtml("logout({$session->id})");
    }

    public function test_reseller_does_not_see_a_logout_button_in_konfigurasi_tab_when_not_connected(): void
    {
        $reseller = Reseller::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $owner = $this->resellerOwner($reseller);
        $session = $this->resellerSession($reseller, WhatsappSessionStatus::QrPending);
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'konfigurasi')
            ->assertDontSeeHtml("logout({$session->id})");
    }

    /**
     * Reseller klik Logout dari sesinya sendiri yang connected (BUKAN
     * rejected_duplicate — tombol Logout memang cuma pernah muncul saat
     * status connected, lihat blade) — status jadi LoggedOut, dan
     * status_reason TETAP null (logout manual biasa, bukan ditolak sistem
     * seperti fitur cegah-dobel-session yang baru dibangun — dua alur
     * yang genuinely berbeda, tidak boleh tertukar pesannya).
     */
    public function test_reseller_can_logout_their_own_session_via_konfigurasi_tab(): void
    {
        Http::fake([
            'whatsapp-gateway-test/sessions/*/logout' => Http::response(['success' => true], 200),
        ]);

        $reseller = Reseller::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $owner = $this->resellerOwner($reseller);
        $session = $this->resellerSession($reseller);
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(WhatsappGatewayIndex::class)
            ->call('logout', $session->id)
            ->assertHasNoErrors();

        $session->refresh();
        $this->assertSame(WhatsappSessionStatus::LoggedOut, $session->status);
        $this->assertNull($session->status_reason);
        $this->assertNotSame('rejected_duplicate', $session->status->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), "whatsapp-gateway-test/sessions/{$reseller->id}/logout"));
    }

    /**
     * Isolasi — reseller B tidak bisa logout sesi milik reseller A, sama
     * sekali TERPISAH dari "reseller tidak bisa logout sesi direct" yang
     * sudah dites di atas (beda skenario: di sini KEDUANYA sama-sama
     * reseller, bukan reseller-vs-admin).
     */
    public function test_reseller_b_cannot_logout_reseller_as_session(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $sessionA = $this->resellerSession($resellerA);
        $ownerB = $this->resellerOwner($resellerB);
        app(ResellerContext::class)->set($resellerB);

        Livewire::actingAs($ownerB)
            ->test(WhatsappGatewayIndex::class)
            ->call('logout', $sessionA->id)
            ->assertForbidden();

        // Sesi A tidak tersentuh sama sekali.
        $this->assertSame(WhatsappSessionStatus::Connected, $sessionA->fresh()->status);
    }
}
