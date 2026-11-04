<?php

namespace Tests\Feature\Installation;

use App\Enums\WorkOrderStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WhatsappMessageLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Installation\WorkOrderClaimService;
use App\Services\Network\TestCredentialSyncService;
use Database\Seeders\WhatsappMessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * v0.13.4.2.2 — form aktivasi signed-link (Blade, bukan Livewire) + WA
 * WorkOrderActivationReady saat klaim sukses. GenieACS di-Http::fake (ONU
 * offline default → WiFi tertunda); radius = sqlite in-memory; tier di-mock.
 * Tidak pernah kirim WhatsApp sungguhan (Queue::fake / template path).
 */
class WorkOrderActivationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.radius' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('radius');
        DB::connection('radius')->statement(
            'CREATE TABLE radcheck (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );
        DB::connection('radius')->statement(
            'CREATE TABLE radreply (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );

        // Default: GenieACS tak kenal serial -> bind pending, WiFi tertunda.
        Http::fake(['*genieacs-nbi*' => Http::response([], 200)]);
    }

    private function fakeTier(string $framedPool = 'POOL-10M'): void
    {
        $this->mock(TestCredentialSyncService::class, function ($mock) use ($framedPool) {
            $mock->shouldReceive('deriveLiveWanParams')->andReturn([
                'has_vlan_package' => true,
                'framed_pool' => $framedPool,
                'vlan_pppoe' => 10,
                'username' => 'ignored',
                'password' => TestCredentialSyncService::PPPOE_PASSWORD,
                'message' => null,
            ]);
        });
    }

    /**
     * @return array{0: WorkOrder, 1: Technician, 2: Customer}
     */
    private function claimedWorkOrder(string $cid = '261000000001'): array
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Ready]);
        $tech = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);
        $wo->update(['claimed_at' => now(), 'claimed_by_technician_id' => $tech->id]);

        $customer = Customer::withoutGlobalScopes()->findOrFail($wo->customer_id);
        Customer::withoutGlobalScopes()->where('id', $customer->id)->update(['cid' => $cid]);
        $customer->update(['name' => 'Budi  Santoso']); // sengaja spasi ganda utk uji normalisasi

        $wo->modemUnits()->create(['serial_number' => 'SNFORM0001', 'mac_address' => '00:aa:bb:cc:dd:ee']);

        return [$wo->fresh(), $tech, $customer->fresh()];
    }

    private function showUrl(WorkOrder $wo, Technician $tech): string
    {
        return URL::temporarySignedRoute('work-orders.activate.show', now()->addDays(2),
            ['work_order' => $wo->id, 'technician' => $tech->id]);
    }

    public function test_claimer_sees_the_activation_form(): void
    {
        [$wo, $tech] = $this->claimedWorkOrder();

        $this->get($this->showUrl($wo, $tech))
            ->assertOk()
            ->assertSee('Aktivasi Pelanggan')
            ->assertSee('261000000001@ppp.bajastu.id')
            ->assertSee('name="ssid"', false)
            ->assertSee('name="customer_name"', false)
            ->assertSee('name="wifi_password"', false);
    }

    public function test_a_non_claimer_technician_sees_a_claimed_by_other_card_not_the_form(): void
    {
        // v0.13.4.5 — teknisi lain (punya signed-link valid sendiri, tapi
        // bukan pengklaim) melihat kartu "sudah diambil", BUKAN 403 kosong
        // dan BUKAN form aktivasi.
        [$wo] = $this->claimedWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $this->get($this->showUrl($wo, $other))
            ->assertOk()
            ->assertSee('sudah diambil')
            ->assertDontSee('name="ssid"', false);
    }

    public function test_tampered_signature_for_another_technician_stays_403(): void
    {
        // v0.13.4.5 — otorisasi TIDAK dilonggarkan: mengganti technician_id di
        // URL tanpa regen signature tetap ditolak middleware 'signed' (403).
        [$wo, $tech] = $this->claimedWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $tampered = str_replace("/{$tech->id}", "/{$other->id}", $this->showUrl($wo, $tech));

        $this->get($tampered)->assertForbidden();
    }

    public function test_submit_by_a_non_claimer_is_rejected_server_side(): void
    {
        // Defense in depth: POST oleh teknisi lain (link valid sendiri) tidak
        // pernah mengaktivasi — kartu "sudah diambil", status WO tak berubah.
        $this->fakeTier();
        [$wo] = $this->claimedWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $this->post($this->showUrl($wo, $other), [
            'customer_name' => 'Budi Santoso', 'ssid' => 'RUMAH', 'wifi_password' => 'rahasia123',
        ])->assertOk()->assertSee('sudah diambil');

        $this->assertSame(WorkOrderStatus::Ready, $wo->fresh()->status);
    }

    public function test_submit_with_matching_name_activates_and_defers_wifi_when_onu_offline(): void
    {
        $this->fakeTier('POOL-10M');
        [$wo, $tech] = $this->claimedWorkOrder();

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => '  budi santoso ', // beda kapital + spasi → tetap cocok
            'ssid' => 'RUMAH-BUDI',
            'wifi_password' => 'rahasia123',
        ])->assertRedirect();

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);

        // SSID/password tersimpan di work_order_devices; wifi_password encrypted.
        $device = WorkOrderDevice::where('work_order_id', $wo->id)->where('serial_number', 'SNFORM0001')->first();
        $this->assertSame('RUMAH-BUDI', $device->ssid);
        $this->assertSame('rahasia123', $device->wifi_password); // dekripsi via cast
        $rawPw = DB::table('work_order_devices')->where('id', $device->id)->value('wifi_password');
        $this->assertNotSame('rahasia123', $rawPw); // tersimpan terenkripsi, bukan plaintext

        // radcheck {cid}@ppp dibuat.
        $this->assertTrue(DB::connection('radius')->table('radcheck')
            ->where('username', '261000000001@ppp.bajastu.id')->exists());

        // ONU offline -> WiFi tertunda: cpe pending, wifi_provisioned_at null.
        $cpe = CpeDevice::withoutGlobalScopes()->where('work_order_device_id', $device->id)->first();
        $this->assertNull($cpe->genieacs_device_id);
        $this->assertNull($cpe->wifi_provisioned_at);
    }

    public function test_submit_with_wrong_name_is_rejected_and_wo_not_activated(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Nama Salah',
            'ssid' => 'X',
            'wifi_password' => 'rahasia123',
        ])->assertOk()->assertSee('tidak cocok');

        $this->assertSame(WorkOrderStatus::Ready, $wo->fresh()->status);
        $this->assertSame(0, WorkOrderDevice::where('work_order_id', $wo->id)->count());
    }

    public function test_submit_with_short_wifi_password_fails_validation(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'ssid' => 'RUMAH',
            'wifi_password' => 'short',
        ])->assertSessionHasErrors('wifi_password');

        $this->assertSame(WorkOrderStatus::Ready, $wo->fresh()->status);
    }

    public function test_second_submit_is_idempotent_shows_status_not_error(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso', 'ssid' => 'RUMAH', 'wifi_password' => 'rahasia123',
        ])->assertRedirect();
        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);

        // Submit kedua (sudah InProgress) -> halaman status, bukan error/500.
        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso', 'ssid' => 'RUMAH', 'wifi_password' => 'rahasia123',
        ])->assertOk()->assertSee('Status Aktivasi');

        $this->assertSame(1, WorkOrderDevice::where('work_order_id', $wo->id)->count());
    }

    public function test_plaintext_wifi_password_never_appears_in_response(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso', 'ssid' => 'RUMAH', 'wifi_password' => 'SangatRahasia99',
        ]);
        $res->assertRedirect();
        $this->followRedirects($res)->assertDontSee('SangatRahasia99');
    }

    public function test_successful_claim_queues_activation_ready_whatsapp(): void
    {
        Queue::fake();
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Ready]);
        $tech = Technician::factory()->create(['tenant_id' => $wo->tenant_id, 'phone' => '08123456789']);
        $this->seed(WhatsappMessageTemplateSeeder::class);

        app(WorkOrderClaimService::class)->submit(
            $wo,
            $tech,
            [],
            [],
            [['serial_number' => 'SNCLAIM1', 'mac_address' => '00:11:22:33:44:55', 'technician_id' => $tech->id]],
        );

        $this->assertTrue(
            WhatsappMessageLog::where('event_type', 'work_order_activation_ready')->exists(),
            'Klaim sukses harus mengantre WA WorkOrderActivationReady.'
        );
        Queue::assertPushed(SendWhatsappMessageJob::class);
    }
}
