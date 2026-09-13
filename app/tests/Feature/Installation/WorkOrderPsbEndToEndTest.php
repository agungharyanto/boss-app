<?php

namespace Tests\Feature\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderPhotoType;
use App\Livewire\Customers\RegisterCustomer;
use App\Models\BandwidthProfile;
use App\Models\Customer;
use App\Models\ModemType;
use App\Models\NetworkProfileGroup;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\PppPackage;
use App\Models\Subscription;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WanConfigTemplate;
use App\Models\WhatsappMessageTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.7 — skenario integrasi end-to-end: 1) registrasi pelanggan + pilih
 * Paket, 2) teknisi scan device (SN + Tipe Modem) + provisioning SSID,
 * 3) konfirmasi OTP WhatsApp teknisi, 4) complete() -> binding + Push
 * Konfig terpanggil otomatis. Menyusun ulang SEMUA yang dibangun
 * v0.12.1-v0.12.7 jadi satu alur PSB utuh lewat jalur HTTP/Livewire nyata
 * (bukan cuma panggil service langsung) — bukti bahwa keempat bagian
 * genuinely nyambung satu sama lain, bukan cuma lolos test terisolasi
 * masing-masing.
 *
 * Pembuatan Subscription sendiri (di luar 4 Langkah v0.12.7 — modul
 * billing/subscription terpisah) dan upload foto asli (bukan bagian dari
 * 4 Langkah ini) TETAP pakai factory langsung, bukan endpoint publik —
 * fokus skenario ini murni pada sambungan Registrasi -> WorkOrder -> OTP
 * -> Push Konfig.
 */
class WorkOrderPsbEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_psb_flow_from_registration_to_confirmed_installation_with_wan_config_push(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Bus::fake();

        $tenant = Tenant::factory()->create();

        // ── Poin 1: Sales daftar calon pelanggan + pilih Paket ──
        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $tenant->id]);
        $package = PppPackage::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        WanConfigTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => $package->id,
            'modem_type_id' => $modemType->id,
            'wan1_enabled' => true,
            'wan1_pppoe_username' => 'pelanggan-baru',
            'wan1_pppoe_password' => 'passwordbaru',
        ]);

        $sales = User::factory()->create(['tenant_id' => $tenant->id]);
        $sales->assignRole('sales_internal');

        Livewire::actingAs($sales)
            ->test(RegisterCustomer::class)
            ->set('name', 'Budi Santoso')
            ->set('phone_number', '081234567890')
            ->set('address', 'Jl. Mawar No. 1')
            ->set('ppp_package_id', $package->id)
            ->set('latitude', -6.2000)
            ->set('longitude', 106.8000)
            ->call('register');

        $customer = Customer::withoutGlobalScopes()->where('phone_number', '081234567890')->firstOrFail();
        $this->assertSame($package->id, $customer->ppp_package_id);

        // ODP terdekat -- tanpa ini createFromSubscription() jatuh ke
        // OdpUnavailable (di luar 4 Langkah v0.12.7, tapi WorkOrder butuh
        // ini untuk genuinely bisa lanjut ke Assigned/InProgress).
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001]);
        OdpPort::factory()->forOdp($odp)->create();

        // ── (di luar 4 Langkah v0.12.7) Subscription + WorkOrder ──
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'reseller_id' => null,
        ]);

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');

        $workOrder = $this->actingAs($admin)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/work-order")
            ->assertCreated()
            ->json('data');
        $workOrderId = $workOrder['id'];

        $this->actingAs($admin)
            ->postJson("/api/v1/work-orders/{$workOrderId}/verify", ['equipment_ready' => true])
            ->assertOk();

        $technicianUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $technicianUser->assignRole('teknisi');
        $technician = Technician::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $technicianUser->id, 'phone' => '081298887777']);

        $this->actingAs($admin)
            ->postJson("/api/v1/work-orders/{$workOrderId}/assign", ['technician_id' => $technician->id])
            ->assertOk();

        $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/start")
            ->assertOk();

        // ── Poin 2: teknisi PSB fisik — SN + Tipe Modem + SSID/Password ──
        $device = $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/devices", [
                'device_type' => 'ont',
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'serial_number' => 'SNE2E00001',
                'modem_type_id' => $modemType->id,
            ])
            ->assertCreated()
            ->json('data');
        $deviceId = $device['id'];

        $this->assertDatabaseHas('work_order_devices', [
            'id' => $deviceId,
            'modem_type_id' => $modemType->id,
        ]);

        $this->actingAs($technicianUser)
            ->patchJson("/api/v1/work-orders/{$workOrderId}/devices/{$deviceId}/provisioning", [
                'ssid' => 'RumahBudi',
                'wifi_password' => 'password123',
            ])
            ->assertOk();

        // Foto (di luar 4 Langkah v0.12.7 — upload asli bukan fokus di sini).
        $workOrderModel = WorkOrder::findOrFail($workOrderId);
        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::factory()->forWorkOrder($workOrderModel)->ofType($type)->create();
        }

        // ── Poin 3: konfirmasi OTP WhatsApp teknisi ──
        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Halo {recipient_name}, kode {otp_code} untuk {action_label}.',
            'is_active' => true,
        ]);

        // complete() ditolak SEBELUM konfirmasi diminta sama sekali.
        $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/complete")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Konfirmasi WhatsApp diperlukan sebelum instalasi bisa diselesaikan.']);

        $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/request-confirmation")
            ->assertOk();

        $code = Cache::get("otp:technician:{$technician->id}:wo-confirm:{$workOrderId}")['code'];

        $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/confirm", ['code' => $code])
            ->assertOk();

        $this->assertNotNull($workOrderModel->fresh()->technician_confirmed_at);

        // ── Poin 4: complete() -> binding CPE + Push Konfig otomatis ──
        Http::fake([
            'genieacs-nbi:7557/devices/*/tasks*' => Http::response(['_id' => 'genieacs-task-e2e-1'], 202),
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'AABBCC-ONT-SNE2E00001',
                '_deviceId' => ['_Manufacturer' => 'Huawei', '_OUI' => 'AABBCC', '_ProductClass' => 'ONT', '_SerialNumber' => 'SNE2E00001'],
                '_lastInform' => now()->toIso8601String(),
                'InternetGatewayDevice' => [
                    'DeviceInfo' => ['ModelName' => ['_value' => 'HG8245H']],
                    'WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
                        'Username' => ['_value' => 'olduser', '_type' => 'xsd:string'],
                        'ConnectionType' => ['_value' => 'IP_Routed', '_type' => 'xsd:string'],
                    ]]]]]],
                ],
            ]], 200),
        ]);

        $this->actingAs($technicianUser)
            ->postJson("/api/v1/work-orders/{$workOrderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // CpeDevice ter-bind dengan Tipe Modem yang teknisi isi (Poin 2 -> Langkah 2).
        $this->assertDatabaseHas('cpe_devices', [
            'work_order_device_id' => $deviceId,
            'customer_id' => $customer->id,
            'serial_number' => 'SNE2E00001',
            'modem_type_id' => $modemType->id,
            'modem_type_source' => 'manual',
        ]);

        // WiFi provisioning (v0.7.5, sudah ada) tetap jalan.
        $this->assertDatabaseHas('cpe_action_logs', [
            'action_type' => 'set_ssid',
            'status' => 'delivered',
        ]);

        // Push Konfig (Poin 4) genuinely terpanggil dan delivered.
        $this->assertDatabaseHas('cpe_action_logs', [
            'action_type' => 'push_wan_config',
            'status' => 'delivered',
        ]);
    }
}
