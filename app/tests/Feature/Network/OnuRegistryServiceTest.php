<?php

namespace Tests\Feature\Network;

use App\Enums\OltAccessProtocol;
use App\Enums\OnuRegistryStatus;
use App\Enums\OnuSyncStatus;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\OnuRegistry;
use App\Models\WorkOrder;
use App\Models\WorkOrderModemUnit;
use App\Services\Network\OnuRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OnuRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.olt_sidecar.url' => 'http://olt-sidecar-test:8080',
            'services.olt_sidecar.hmac_secret' => 'test-shared-secret',
        ]);
    }

    private function makeOltDevice(string $manufacturer, string $model, OltAccessProtocol $protocol = OltAccessProtocol::Telnet): OltDevice
    {
        $olt = OltManufacturer::factory()->create(['name' => $manufacturer]);
        $modelRow = OltModel::factory()->create(['olt_manufacturer_id' => $olt->id, 'name' => $model]);
        $nas = Nas::factory()->create();

        return OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'olt_model_id' => $modelRow->id,
            'access_protocol' => $protocol,
        ]);
    }

    private function zte(): OltDevice
    {
        return $this->makeOltDevice('ZTE', 'C300', OltAccessProtocol::Telnet);
    }

    public function test_sync_onu_stores_a_registry_row_for_zte_with_active_status(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'Name' => 'Test-1',
                    'Type' => 'M12X5G_XPON',
                    'State' => 'ready',
                    'Phase state' => 'working',
                    'Description' => 'none',
                ],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $olt = $this->zte();
        $registry = app(OnuRegistryService::class)->syncOnu($olt, 'gpon-onu_1/3/12:2');

        $this->assertSame($olt->id, $registry->olt_device_id);
        $this->assertSame('gpon-onu_1/3/12:2', $registry->vendor_identifier);
        $this->assertSame(OnuRegistryStatus::Active, $registry->status);
        $this->assertSame(OnuSyncStatus::Synced, $registry->sync_status);
        $this->assertNotNull($registry->name);
        // Description "none" dinormalisasi jadi null, bukan string "none".
        $this->assertNull($registry->description);
        $this->assertNotNull($registry->last_synced_at);
        $this->assertIsArray($registry->raw_payload);
    }

    public function test_sync_onu_sends_mask_sensitive_false_to_the_sidecar(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => [], 'raw_excerpt' => null, 'device_message' => null], 200),
        ]);

        $olt = $this->zte();
        app(OnuRegistryService::class)->syncOnu($olt, 'gpon-onu_1/3/12:2');

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $this->assertFalse($payload['mask_sensitive']);
            $this->assertSame('onu_detail_info', $payload['operation']);
            $this->assertSame(['onu' => 'gpon-onu_1/3/12:2'], $payload['args']);

            return true;
        });
    }

    public function test_sync_onu_maps_offline_phase_state_correctly(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => ['State' => 'ready', 'Phase state' => 'OffLine'],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $registry = app(OnuRegistryService::class)->syncOnu($this->zte(), 'gpon-onu_1/3/12:1');

        $this->assertSame(OnuRegistryStatus::Offline, $registry->status);
    }

    public function test_sync_onu_maps_unconfigured_status_from_uncfg_style_state(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => ['State' => 'unknown'],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $registry = app(OnuRegistryService::class)->syncOnu($this->zte(), 'gpon-onu_1/3/12:9');

        $this->assertSame(OnuRegistryStatus::Unconfigured, $registry->status);
    }

    public function test_sync_onu_falls_back_to_unknown_status_for_an_unrecognized_state_combination(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => ['State' => 'sesuatu-yang-belum-pernah-terlihat'],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $registry = app(OnuRegistryService::class)->syncOnu($this->zte(), 'gpon-onu_1/3/12:9');

        $this->assertSame(OnuRegistryStatus::Unknown, $registry->status);
    }

    public function test_sync_onu_leaves_serial_number_null_when_the_sn_field_is_not_present(): void
    {
        // Catatan jujur desain (docs/omci/onu-registry-design.md §6): field
        // "Sn" belum terkonfirmasi ada di output detail-info — ini
        // memverifikasi service TIDAK error kalau field itu genuinely tidak
        // ada, bukan menebak/memaksakan nilai.
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => ['Name' => 'Test-1', 'State' => 'ready'],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $registry = app(OnuRegistryService::class)->syncOnu($this->zte(), 'gpon-onu_1/3/12:2');

        $this->assertNull($registry->serial_number);
    }

    public function test_sync_onu_rejects_hsgq_e04id_explicitly_without_calling_the_sidecar(): void
    {
        Http::fake();

        $olt = $this->makeOltDevice('HSGQ', 'HSGQ-E04ID', OltAccessProtocol::Ssh);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum diverifikasi terhadap OLT nyata');

        try {
            app(OnuRegistryService::class)->syncOnu($olt, 'epon1/5');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sync_onu_rejects_hsgq_g02id_explicitly_without_calling_the_sidecar(): void
    {
        Http::fake();

        $olt = $this->makeOltDevice('HSGQ', 'HSGQ-G02ID', OltAccessProtocol::Ssh);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum diverifikasi terhadap OLT nyata');

        try {
            app(OnuRegistryService::class)->syncOnu($olt, 'gpon1/5');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sync_onu_failure_marks_an_existing_row_stale_without_touching_its_other_fields(): void
    {
        $olt = $this->zte();

        // Http::fake() dipanggil ulang TIDAK override stub sebelumnya —
        // Laravel mencocokkan berdasarkan urutan PENDAFTARAN (first-match-
        // wins), bukan yang paling baru (dikonfirmasi nyata sebelum
        // dipercaya di sini). Untuk 2 respons berurutan pada URL yang
        // sama, fakeSequence() adalah alat yang benar.
        Http::fakeSequence()
            ->push([
                'success' => true,
                'data' => ['Name' => 'Test-1', 'State' => 'ready', 'Phase state' => 'working'],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200)
            ->push(['success' => false, 'device_message' => 'Timeout menunggu prompt'], 502);

        $registry = app(OnuRegistryService::class)->syncOnu($olt, 'gpon-onu_1/3/12:2');
        $this->assertSame(OnuSyncStatus::Synced, $registry->sync_status);

        // TIDAK pakai $this->fail() di dalam try — PHPUnit\Framework\
        // AssertionFailedError (yang dilempar fail()) ternyata SUBCLASS
        // RuntimeException di versi PHPUnit ini, jadi akan tertangkap
        // balik oleh catch di bawah (ditemukan nyata menulis test ini).
        $thrownMessage = null;
        try {
            app(OnuRegistryService::class)->syncOnu($olt, 'gpon-onu_1/3/12:2');
        } catch (RuntimeException $exception) {
            $thrownMessage = $exception->getMessage();
        }
        $this->assertNotNull($thrownMessage, 'Expected a RuntimeException to be thrown.');
        $this->assertStringContainsString('Timeout menunggu prompt', $thrownMessage);

        $registry->refresh();
        $this->assertSame(OnuSyncStatus::Stale, $registry->sync_status);
        // Status ONU/nama LAMA dipertahankan apa adanya — bukan dihapus/reset.
        $this->assertSame(OnuRegistryStatus::Active, $registry->status);
        $this->assertNotNull($registry->name);
    }

    public function test_sync_onu_failure_creates_no_row_at_all_when_none_existed_before(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'error' => 'timeout'], 502),
        ]);

        $olt = $this->zte();

        $threw = false;
        try {
            app(OnuRegistryService::class)->syncOnu($olt, 'gpon-onu_1/3/12:2');
        } catch (RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected a RuntimeException to be thrown.');

        $this->assertSame(0, OnuRegistry::withoutGlobalScopes()->count());
    }

    public function test_find_work_order_match_returns_null_when_both_identifiers_are_null(): void
    {
        $this->assertNull(app(OnuRegistryService::class)->findWorkOrderMatch(null, null));
    }

    public function test_find_work_order_match_finds_by_serial_number(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $unit = WorkOrderModemUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'serial_number' => 'DUMMY-SN-0001',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ]);

        $match = app(OnuRegistryService::class)->findWorkOrderMatch('DUMMY-SN-0001', null);

        $this->assertSame($unit->id, $match?->id);
    }

    public function test_find_work_order_match_returns_the_most_recent_row_when_multiple_match(): void
    {
        $workOrder = WorkOrder::factory()->create();
        WorkOrderModemUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'serial_number' => 'DUMMY-SN-DUP',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'created_at' => now()->subDays(5),
        ]);
        $newest = WorkOrderModemUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'serial_number' => 'DUMMY-SN-DUP',
            'mac_address' => 'AA:BB:CC:DD:EE:03',
            'created_at' => now(),
        ]);

        $match = app(OnuRegistryService::class)->findWorkOrderMatch('DUMMY-SN-DUP', null);

        $this->assertSame($newest->id, $match?->id);
    }

    public function test_find_work_order_match_returns_null_when_nothing_matches(): void
    {
        $this->assertNull(app(OnuRegistryService::class)->findWorkOrderMatch('TIDAK-ADA-SN', 'TIDAK:ADA:MAC'));
    }
}
