<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappSessionStatus;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Redesign trigger overlay QR — dari counter tebakan waktu (3s×5x, dihapus
 * total, lihat riwayat WhatsappQrAutoRefreshLimitTest yang sudah dihapus)
 * jadi sinyal GENUINE dari whatsmeow: overlay reload muncul murni dari
 * `whatsapp_sessions.qr_expired_at`, diisi webhook `session-status` saat
 * drainQRChannel()'s "timeout" non-pertama (lihat WhatsappSessionWebhookTest
 * untuk sisi webhook-nya). `wire:poll` juga tidak lagi punya method khusus —
 * murni re-render ($refresh default), dan HANYA aktif selama status
 * genuinely `qr_pending`.
 */
class WhatsappQrExpiredOverlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.whatsapp_gateway.url' => 'http://whatsapp-gateway-test']);
    }

    private function directSession(int $tenantId, ?Carbon $qrExpiredAt = null): WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reseller_id' => null,
            'status' => WhatsappSessionStatus::QrPending,
            'qr_code_data' => 'data:image/png;base64,AAAA',
            'qr_expired_at' => $qrExpiredAt,
        ]);
    }

    private function admin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_overlay_does_not_appear_while_qr_expired_at_is_null(): void
    {
        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id, null);

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->assertDontSeeHtml('QR sudah kedaluwarsa — klik untuk muat ulang')
            ->assertSeeHtml('wire:poll.3s');

        $this->assertNull($session->fresh()->qr_expired_at);
    }

    public function test_overlay_appears_once_qr_expired_at_is_set(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id, now());

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->assertSeeHtml('QR sudah kedaluwarsa — klik untuk muat ulang')
            ->assertSeeHtml('QR ini sudah kedaluwarsa (tidak lagi bisa discan)');
    }

    /**
     * wire:poll HANYA aktif selama status genuinely qr_pending — status
     * lain (disconnected/logged_out/rejected_duplicate) tidak ada gunanya
     * dipoll terus (backend tidak akan mendorong update baru tanpa aksi
     * manual), efek samping baik dari redesign ini di luar soal QR
     * kedaluwarsa itu sendiri.
     */
    public function test_wire_poll_is_absent_once_status_is_no_longer_qr_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $session = $this->directSession($tenant->id);
        $session->update(['status' => WhatsappSessionStatus::Disconnected]);

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->assertDontSeeHtml('wire:poll.3s');
    }

    public function test_wire_poll_is_present_while_status_is_qr_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $this->directSession($tenant->id);

        Livewire::actingAs($this->admin($tenant))
            ->test(WhatsappGatewayIndex::class)
            ->assertSeeHtml('wire:poll.3s');
    }

    /**
     * Overlay-nya sendiri tidak butuh method Livewire khusus lagi (dulu
     * pollQr()) — component ini benar-benar tidak lagi mendefinisikan
     * method itu sama sekali.
     */
    public function test_the_component_no_longer_has_a_poll_qr_method(): void
    {
        $this->assertFalse(method_exists(WhatsappGatewayIndex::class, 'pollQr'));
    }
}
