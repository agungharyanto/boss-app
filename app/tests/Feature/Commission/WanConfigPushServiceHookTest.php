<?php

namespace Tests\Feature\Commission;

use App\Enums\CpeActionType;
use App\Models\BandwidthProfile;
use App\Models\CommissionRate;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\SubscriptionRenewalService;
use App\Services\Network\WanConfigPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.6 Bagian 1 — hook Push Konfig di
 * SubscriptionRenewalService::renew(). Fokus: TITIK pemanggilan (hanya
 * saat ganti paket, satu per CpeDevice milik customer), bukan detail
 * hasil push itu sendiri (delivered/failed/skipped di level
 * WanConfigPushService sudah dicakup penuh oleh
 * Tests\Feature\Network\WanConfigPushServiceTest) — di sini sengaja
 * memakai skenario "skip" (tidak ada Template cocok) supaya tidak perlu
 * Http::fake() GenieACS sama sekali, konsisten dengan bagaimana test lama
 * SubscriptionRenewalServiceTest sudah lolos tanpa itu.
 */
class WanConfigPushServiceHookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SubscriptionRenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->service = app(SubscriptionRenewalService::class);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        return $user;
    }

    private function package(): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create(['tenant_id' => $this->tenant->id]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);

        CommissionRate::factory()->create(['ppp_package_id' => $package->id, 'recurring_amount' => 5000]);

        return $package;
    }

    public function test_renew_with_a_package_change_calls_push_for_every_cpe_device_and_records_the_result(): void
    {
        $user = $this->actingUser();
        $oldPackage = $this->package();
        $newPackage = $this->package();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $oldPackage->id,
        ]);
        $deviceA = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);
        $deviceB = CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $result = $this->service->renew($user, $customer, $newPackage->id);

        $this->assertTrue($result['package_changed']);
        $this->assertCount(2, $result['wan_config_push_results']);

        $deviceIds = collect($result['wan_config_push_results'])->pluck('cpe_device_id')->all();
        $this->assertEqualsCanonicalizing([$deviceA->id, $deviceB->id], $deviceIds);

        // Tidak ada Template Konfig CPE untuk paket baru -> skipped, BUKAN
        // error -- sesuai instruksi "null-result = skip bukan error".
        foreach ($result['wan_config_push_results'] as $row) {
            $this->assertSame('skipped', $row['status']);
        }

        // Riwayat Aksi genuinely tercatat, satu baris per device.
        $this->assertSame(2, CpeActionLog::withoutGlobalScopes()
            ->where('action_type', CpeActionType::PushWanConfig->value)
            ->count());
    }

    public function test_renew_without_a_package_change_never_calls_push(): void
    {
        $user = $this->actingUser();
        $package = $this->package();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package->id,
        ]);
        CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        // newPppPackageId = null -> tidak ganti paket sama sekali.
        $result = $this->service->renew($user, $customer, null);

        $this->assertFalse($result['package_changed']);
        $this->assertSame([], $result['wan_config_push_results']);
        $this->assertSame(0, CpeActionLog::withoutGlobalScopes()
            ->where('action_type', CpeActionType::PushWanConfig->value)
            ->count());
    }

    public function test_renew_to_the_same_package_id_never_calls_push(): void
    {
        $user = $this->actingUser();
        $package = $this->package();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package->id,
        ]);
        CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        // newPppPackageId sama dengan paket sekarang -> bukan "ganti paket".
        $result = $this->service->renew($user, $customer, $package->id);

        $this->assertFalse($result['package_changed']);
        $this->assertSame([], $result['wan_config_push_results']);
    }

    public function test_renew_with_a_package_change_but_no_cpe_devices_at_all_records_an_empty_array(): void
    {
        $user = $this->actingUser();
        $oldPackage = $this->package();
        $newPackage = $this->package();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $oldPackage->id,
        ]);

        $result = $this->service->renew($user, $customer, $newPackage->id);

        $this->assertTrue($result['package_changed']);
        $this->assertSame([], $result['wan_config_push_results']);
    }

    public function test_a_wan_config_push_failure_is_best_effort_and_never_rolls_back_the_renewal_transaction(): void
    {
        // WanConfigPushService yang throw langsung -- membuktikan try/catch
        // di renew() genuinely melindungi transaksi Ganti Paket/Perpanjang
        // itu sendiri, bukan cuma diasumsikan dari cara kodenya ditulis.
        $this->app->instance(WanConfigPushService::class, new class extends WanConfigPushService
        {
            public function __construct() {}

            public function push(CpeDevice $device, ?User $actor): CpeActionLog
            {
                throw new \RuntimeException('GenieACS unreachable (simulasi test).');
            }
        });

        $service = app(SubscriptionRenewalService::class);

        $user = $this->actingUser();
        $oldPackage = $this->package();
        $newPackage = $this->package();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $oldPackage->id,
        ]);
        CpeDevice::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        // TIDAK melempar exception ke pemanggil -- best-effort.
        $result = $service->renew($user, $customer, $newPackage->id);

        $this->assertTrue($result['package_changed']);
        $this->assertSame($newPackage->id, $customer->fresh()->ppp_package_id);
        // Push gagal -> tidak masuk wan_config_push_results (di-log warning
        // saja, bukan dicatat sebagai baris hasil).
        $this->assertSame([], $result['wan_config_push_results']);

        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }
}
