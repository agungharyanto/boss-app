<?php

namespace Tests\Feature\Installation;

use App\Enums\WhatsappEventType;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappNotificationGroup;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderPhoto;
use App\Services\Installation\WorkOrderDispatchService;
use App\Services\Installation\WorkOrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkOrderCompletedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '120363000000000000@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        // GenieACS bind/push di complete() = best-effort; fake agar tak kena jaringan.
        Http::fake(['*' => Http::response([], 200)]);
    }

    /** @return array{0: WorkOrder, 1: Technician} */
    private function completableWo(?string $groupJid = self::GROUP, bool $withTemplate = true): array
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);
        Customer::withoutGlobalScopes()->whereKey($wo->customer_id)->update(['name' => 'Budi Santoso', 'cid' => '2026999001']);
        $tech = Technician::factory()->create(['tenant_id' => $wo->tenant_id, 'name' => 'Teknisi Uji']);
        $wo->update([
            'claimed_at' => now(),
            'claimed_by_technician_id' => $tech->id,
            'technician_id' => $tech->id,
            'technician_confirmed_at' => now(),
        ]);

        WorkOrderDevice::create([
            'work_order_id' => $wo->id,
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNWO'.$wo->id,
            'mac_address' => '00:aa:bb:cc:dd:ee',
            'scanned_at' => now(),
            'ssid' => 'RUMAHKU',
        ]);

        foreach (WorkOrderPhotoType::cases() as $type) {
            WorkOrderPhoto::create([
                'work_order_id' => $wo->id,
                'type' => $type->value,
                'file_path' => 'work-order-photos/'.$wo->id.'/'.$type->value.'.jpg',
                'uploaded_at' => now(),
            ]);
        }

        // v0.13.4.4 — grup tujuan di-resolve via WhatsappGroupRouter;
        // notify memakai grup DEFAULT sesi 'direct' (tanpa routing khusus).
        if ($groupJid !== null) {
            WhatsappNotificationGroup::create([
                'tenant_id' => $wo->tenant_id,
                'session_key' => 'direct',
                'jid' => $groupJid,
                'label' => 'Grup Utama',
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        if ($withTemplate) {
            WhatsappMessageTemplate::withoutGlobalScopes()->create([
                'tenant_id' => $wo->tenant_id,
                'reseller_id' => null,
                'event_type' => WhatsappEventType::WorkOrderCompleted->value,
                'content' => 'WO #{work_order_id} SELESAI. Teknisi {technician_name}. Pelanggan {customer_name} (CID {customer_cid}). SSID {ssid}. Layanan {service_type}. Password WiFi: ****',
                'is_active' => true,
            ]);
        }

        return [$wo->fresh(), $tech];
    }

    private function dispatch(): WorkOrderDispatchService
    {
        return app(WorkOrderDispatchService::class);
    }

    public function test_notify_creates_text_plus_six_labeled_media_logs(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();

        $this->dispatch()->notifyWorkOrderCompleted($wo);

        $logs = WhatsappMessageLog::withoutGlobalScopes()
            ->where('phone_number', self::GROUP)
            ->where('event_type', WhatsappEventType::WorkOrderCompleted->value)
            ->orderBy('id')->get();

        $this->assertCount(7, $logs, '1 teks + 6 foto');

        // Teks (tanpa attachment) = log pertama, memuat **** dan TIDAK ada password nyata.
        $text = $logs->firstWhere('attachment', null);
        $this->assertNotNull($text);
        $this->assertStringContainsString('Password WiFi: ****', $text->rendered_content);
        $this->assertStringContainsString('2026999001', $text->rendered_content);
        $this->assertStringContainsString('RUMAHKU', $text->rendered_content);

        // 6 media berlabel, urut, prefix work-order-photos/.
        $media = $logs->whereNotNull('attachment')->values();
        $this->assertCount(6, $media);
        $expected = ['1/6 Speedtest', '2/6 Lokasi modem', '3/6 Jalur kabel IKR', '4/6 Port ODP', '5/6 Box ODP', '6/6 Tampak depan pelanggan'];
        foreach ($expected as $i => $label) {
            $this->assertSame($label.' · WO #'.$wo->id, $media[$i]->rendered_content);
            $this->assertStringStartsWith('work-order-photos/', $media[$i]->attachment['path']);
        }
    }

    public function test_notify_skips_silently_when_group_jid_null(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo(groupJid: null);

        $this->dispatch()->notifyWorkOrderCompleted($wo);

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count());
    }

    public function test_notify_skips_missing_photo_types(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();
        WorkOrderPhoto::where('work_order_id', $wo->id)->whereIn('type', ['odp_box', 'customer_front'])->delete();

        $this->dispatch()->notifyWorkOrderCompleted($wo);

        // 1 teks + 4 foto (2 dilewati).
        $this->assertSame(5, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count());
    }

    public function test_complete_triggers_notify_after_commit(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();

        app(WorkOrderService::class)->complete($wo);

        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);
        $this->assertSame(7, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count());
    }

    public function test_rolled_back_transaction_does_not_notify(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();

        try {
            DB::transaction(function () use ($wo) {
                app(WorkOrderService::class)->complete($wo);
                throw new \RuntimeException('rollback paksa');
            });
        } catch (\RuntimeException) {
            // ditelan — yang penting afterCommit TIDAK jalan.
        }

        $this->assertSame(0, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count());
        $this->assertNotSame(WorkOrderStatus::Completed, $wo->fresh()->status);
    }

    public function test_completing_twice_does_not_double_notify(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();

        app(WorkOrderService::class)->complete($wo);
        $countAfterFirst = WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count();

        $this->expectException(InvalidWorkOrderStatusTransitionException::class);
        try {
            app(WorkOrderService::class)->complete($wo->fresh());
        } finally {
            $this->assertSame($countAfterFirst, WhatsappMessageLog::withoutGlobalScopes()->where('event_type', WhatsappEventType::WorkOrderCompleted->value)->count());
        }
    }

    public function test_close_succeeds_even_when_notify_throws(): void
    {
        Queue::fake();
        [$wo] = $this->completableWo();

        // Paksa notify melempar — complete() HARUS tetap sukses (try/catch di afterCommit).
        $this->mock(WorkOrderDispatchService::class, function ($m) {
            $m->shouldReceive('notifyWorkOrderCompleted')->andThrow(new \RuntimeException('boom WA'));
        });

        $result = app(WorkOrderService::class)->complete($wo);

        $this->assertSame(WorkOrderStatus::Completed, $result->status);
    }

    public function test_api_photo_endpoint_rejects_webp_and_accepts_png(): void
    {
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        [$wo] = $this->completableWo();
        $admin = User::factory()->create(['tenant_id' => $wo->tenant_id]);
        $admin->assignRole('superadmin');
        Sanctum::actingAs($admin);

        // WebP ditolak (mimes:jpg,jpeg,png).
        $this->post("/api/v1/work-orders/{$wo->id}/photos", [
            'type' => WorkOrderPhotoType::Speedtest->value,
            'file' => UploadedFile::fake()->create('x.webp', 50, 'image/webp'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // PNG diterima.
        $this->post("/api/v1/work-orders/{$wo->id}/photos", [
            'type' => WorkOrderPhotoType::Speedtest->value,
            'file' => UploadedFile::fake()->create('x.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->assertSuccessful();
    }
}
