<?php

namespace Tests\Feature\Installation;

use App\Enums\CpeDeviceStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderActivationException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Installation\WorkOrderActivationService;
use App\Services\Network\TestCredentialSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.13.4.2.1 — orkestrasi aktivasi DASAR (salin modem -> bind CPE -> radcheck
 * -> InProgress). TestCredentialSyncService di-mock (tier/VLAN/framed_pool
 * sudah diuji terpisah); GenieACS di-Http::fake -> device belum pernah inform
 * (null) sehingga bind jadi pending_first_connect & WiFi TIDAK dipush (itu
 * scope .2.2). Koneksi 'radius' dipetakan ke sqlite in-memory (pola sama
 * CtcomActivationServiceTest).
 */
class WorkOrderActivationServiceTest extends TestCase
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

        // Default: GenieACS tidak kenal serial apa pun -> bind pending_first_connect.
        Http::fake(['*genieacs-nbi*' => Http::response([], 200)]);
    }

    private function fakeTier(string $framedPool = 'POOL-10M'): void
    {
        $this->mock(TestCredentialSyncService::class, function ($mock) use ($framedPool) {
            $mock->shouldReceive('deriveLiveWanParams')->andReturn([
                'has_vlan_package' => true,
                'framed_pool' => $framedPool,
                'vlan_pppoe' => 10,
                'username' => 'ignored-service-builds-its-own',
                'password' => TestCredentialSyncService::PPPOE_PASSWORD,
                'message' => null,
            ]);
        });
    }

    private function service(): WorkOrderActivationService
    {
        return app(WorkOrderActivationService::class);
    }

    /**
     * WO ter-klaim + status Ready/Assigned, modem unit, customer ber-cid.
     *
     * @return array{0: WorkOrder, 1: Technician, 2: Customer}
     */
    private function claimedWorkOrder(
        WorkOrderStatus $status = WorkOrderStatus::Ready,
        string $cid = '261000000001',
        int $modemUnits = 1,
    ): array {
        $wo = WorkOrder::factory()->create(['status' => $status]);
        $tech = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);
        $wo->update(['claimed_at' => now(), 'claimed_by_technician_id' => $tech->id]);

        $customer = Customer::withoutGlobalScopes()->findOrFail($wo->customer_id);
        // cid TIDAK fillable (di-generate CustomerObserver saat created) — set
        // deterministik lewat query builder mentah untuk test ini.
        Customer::withoutGlobalScopes()->where('id', $customer->id)->update(['cid' => $cid === '' ? null : $cid]);
        $customer->update(['phone_number' => '08123456789']);

        for ($i = 0; $i < $modemUnits; $i++) {
            $wo->modemUnits()->create([
                'serial_number' => 'SNACT'.$i.'0001',
                'mac_address' => '00:11:22:33:44:0'.$i,
            ]);
        }

        return [$wo->fresh(), $tech, $customer->fresh()];
    }

    private function insertRadcheck(string $username): void
    {
        DB::connection('radius')->table('radcheck')->insert([
            'username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'x',
        ]);
    }

    public function test_happy_path_copies_modem_binds_writes_radcheck_and_moves_to_in_progress(): void
    {
        $this->fakeTier('POOL-10M');
        [$wo, $tech] = $this->claimedWorkOrder();

        $result = $this->service()->prepare($wo, $tech);

        // WO -> InProgress, technician_id = pengklaim.
        $fresh = $wo->fresh();
        $this->assertSame(WorkOrderStatus::InProgress, $fresh->status);
        $this->assertSame($tech->id, $fresh->technician_id);

        // modem -> work_order_devices (ont).
        $device = WorkOrderDevice::where('work_order_id', $wo->id)->first();
        $this->assertNotNull($device);
        $this->assertSame(WorkOrderDeviceType::Ont, $device->device_type);
        $this->assertSame('SNACT00001', $device->serial_number);

        // CPE bound pending (ONU belum inform).
        $this->assertSame(CpeDeviceStatus::PendingFirstConnect->value, $result['cpe_status']);

        // radcheck {cid}@ppp + password konstanta + Framed-Pool dari tier.
        $this->assertSame('261000000001@ppp.bajastu.id', $result['pppoe_username']);
        $row = DB::connection('radius')->table('radcheck')
            ->where('username', '261000000001@ppp.bajastu.id')->first();
        $this->assertNotNull($row);
        $this->assertSame('wifijadipasti', $row->value);
        $pool = DB::connection('radius')->table('radreply')
            ->where('username', '261000000001@ppp.bajastu.id')
            ->where('attribute', 'Framed-Pool')->value('value');
        $this->assertSame('POOL-10M', $pool);
    }

    public function test_missing_cid_is_rejected(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder(cid: '');
        // cid dikosongkan via helper di atas tidak bisa '' lewat update unik; set null eksplisit.
        Customer::withoutGlobalScopes()->where('id', $wo->customer_id)->update(['cid' => null]);

        $this->expectException(WorkOrderActivationException::class);
        $this->expectExceptionMessage('belum punya CID');
        $this->service()->prepare($wo->fresh(), $tech);
    }

    public function test_an_existing_different_radcheck_username_blocks_activation(): void
    {
        $this->fakeTier();
        [$wo, $tech, $customer] = $this->claimedWorkOrder();
        // Pelanggan sudah punya username migrasi (= phone_number) di radcheck.
        $this->insertRadcheck($customer->phone_number);

        $this->expectException(WorkOrderActivationException::class);
        $this->expectExceptionMessage('username RADIUS lain');
        $this->service()->prepare($wo, $tech);

        // WO TIDAK berpindah (rollback / belum sempat start).
        $this->assertSame(WorkOrderStatus::Ready, $wo->fresh()->status);
    }

    public function test_an_existing_cid_username_does_not_block_write_is_idempotent(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();
        // {cid}@ppp sudah ada -> TIDAK memblokir (write idempoten menimpanya).
        $this->insertRadcheck('261000000001@ppp.bajastu.id');

        $result = $this->service()->prepare($wo, $tech);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
        // Tetap 1 baris radcheck untuk username itu (delete-then-insert).
        $count = DB::connection('radius')->table('radcheck')
            ->where('username', '261000000001@ppp.bajastu.id')->count();
        $this->assertSame(1, $count);
        $this->assertSame('261000000001@ppp.bajastu.id', $result['pppoe_username']);
    }

    public function test_no_modem_units_is_rejected(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder(modemUnits: 0);

        $this->expectException(WorkOrderActivationException::class);
        $this->expectExceptionMessage('belum punya unit modem');
        $this->service()->prepare($wo, $tech);
    }

    public function test_actor_not_the_claimer_is_rejected(): void
    {
        $this->fakeTier();
        [$wo] = $this->claimedWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $this->expectException(WorkOrderActivationException::class);
        $this->expectExceptionMessage('yang mengklaim');
        $this->service()->prepare($wo, $other);
    }

    public function test_illegal_status_is_rejected_with_clear_message(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder(status: WorkOrderStatus::PendingVerification);

        $this->expectException(WorkOrderActivationException::class);
        $this->expectExceptionMessage('tidak bisa diaktivasi');
        $this->service()->prepare($wo, $tech);
    }

    public function test_assigned_status_also_activates(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder(status: WorkOrderStatus::Assigned);

        $this->service()->prepare($wo, $tech);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
        $this->assertSame($tech->id, $wo->fresh()->technician_id);
    }

    public function test_copying_modem_is_idempotent_no_duplicate_device(): void
    {
        $this->fakeTier();
        [$wo, $tech] = $this->claimedWorkOrder();
        // Device dgn SN yang sama SUDAH ada (mis. sisa percobaan sebelumnya).
        WorkOrderDevice::create([
            'work_order_id' => $wo->id,
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNACT00001',
            'mac_address' => 'ff:ff:ff:ff:ff:ff',
            'scanned_at' => now(),
        ]);

        $this->service()->prepare($wo, $tech);

        // Tetap SATU baris device untuk WO+SN itu (updateOrCreate, bukan insert baru).
        $count = WorkOrderDevice::where('work_order_id', $wo->id)
            ->where('serial_number', 'SNACT00001')->count();
        $this->assertSame(1, $count);
        // MAC ter-update ke nilai dari modem unit (updateOrCreate menimpa).
        $this->assertSame('00:11:22:33:44:00', WorkOrderDevice::where('work_order_id', $wo->id)
            ->where('serial_number', 'SNACT00001')->value('mac_address'));
    }
}
