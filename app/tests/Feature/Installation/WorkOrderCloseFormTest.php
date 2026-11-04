<?php

namespace Tests\Feature\Installation;

use App\Enums\CpeDeviceStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * v0.13.4.3.1 — form web tutup WO (signed-link). GenieACS di-Http::fake
 * (complete() memanggil bind/push best-effort). Disk 'local' di-fake agar
 * foto/tanda tangan tidak menyentuh storage nyata.
 */
class WorkOrderCloseFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['*genieacs-nbi*' => Http::response([], 200)]);
    }

    /** PNG valid yang di-encode manual (tanpa GD/Imagick). */
    private function rawPng(int $w, int $h, bool $noise): string
    {
        $sig = "\x89PNG\r\n\x1a\n";
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $ihdr = pack('N2C5', $w, $h, 8, 2, 0, 0, 0); // 8-bit, colortype 2 (RGB)
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $w; $x++) {
                $raw .= $noise
                    ? chr(random_int(0, 255)).chr(random_int(0, 255)).chr(random_int(0, 255))
                    : "\xff\xff\xff";
            }
        }

        return $sig.$chunk('IHDR', $ihdr).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }

    private function fakePhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('foto.png', $this->rawPng(60, 60, false));
    }

    /** @return array<string, UploadedFile> keyed by photo type value */
    private function sixPhotos(): array
    {
        $out = [];
        foreach (WorkOrderPhotoType::cases() as $type) {
            $out[$type->value] = $this->fakePhoto();
        }

        return $out;
    }

    private function signatureDataUri(): string
    {
        return 'data:image/png;base64,'.base64_encode($this->rawPng(200, 100, true));
    }

    /**
     * @param  bool  $onuReady  cpe online + wifi_provisioned_at terisi
     * @return array{0: WorkOrder, 1: Technician}
     */
    private function makeWorkOrder(
        WorkOrderStatus $status = WorkOrderStatus::InProgress,
        bool $onuReady = true,
        string $customerName = 'Budi Santoso',
    ): array {
        $wo = WorkOrder::factory()->create(['status' => $status]);
        $tech = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);
        $wo->update(['claimed_at' => now(), 'claimed_by_technician_id' => $tech->id, 'technician_id' => $tech->id]);

        Customer::withoutGlobalScopes()->where('id', $wo->customer_id)->update(['name' => $customerName]);

        $device = WorkOrderDevice::create([
            'work_order_id' => $wo->id,
            'device_type' => WorkOrderDeviceType::Ont,
            'serial_number' => 'SNCLOSE1',
            'mac_address' => '00:aa:bb:cc:dd:ee',
            'scanned_at' => now(),
        ]);

        CpeDevice::withoutGlobalScopes()->create([
            'tenant_id' => $wo->tenant_id,
            'customer_id' => $wo->customer_id,
            'work_order_device_id' => $device->id,
            'serial_number' => 'SNCLOSE1',
            'genieacs_device_id' => $onuReady ? 'OUI-Model-SNCLOSE1' : null,
            'status' => $onuReady ? CpeDeviceStatus::Online : CpeDeviceStatus::Offline,
            'wifi_provisioned_at' => $onuReady ? now() : null,
        ]);

        return [$wo->fresh(), $tech];
    }

    private function showUrl(WorkOrder $wo, Technician $tech): string
    {
        return URL::temporarySignedRoute('work-orders.close.show', now()->addDays(2),
            ['work_order' => $wo->id, 'technician' => $tech->id]);
    }

    public function test_a_non_claimer_technician_sees_a_claimed_by_other_card_not_the_form(): void
    {
        // v0.13.4.5 — teknisi lain (signed-link valid sendiri, bukan
        // pengklaim) melihat kartu "sudah diambil", BUKAN 403 dan BUKAN form.
        [$wo] = $this->makeWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $this->get($this->showUrl($wo, $other))
            ->assertOk()
            ->assertSee('sudah diambil')
            ->assertDontSee('name="signature"', false);
    }

    public function test_tampered_signature_for_another_technician_stays_403(): void
    {
        // v0.13.4.5 — otorisasi inti tetap di middleware 'signed': mengubah
        // technician_id tanpa regen signature tetap 403.
        [$wo, $tech] = $this->makeWorkOrder();
        $other = Technician::factory()->create(['tenant_id' => $wo->tenant_id]);

        $tampered = str_replace("/{$tech->id}", "/{$other->id}", $this->showUrl($wo, $tech));

        $this->get($tampered)->assertForbidden();
    }

    public function test_gate_blocks_server_side_when_onu_not_ready(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(onuReady: false);

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ]);

        $res->assertOk()->assertSee('Belum bisa menutup');
        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_work_order_not_in_progress_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(status: WorkOrderStatus::Ready, onuReady: true);

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ]);

        $res->assertOk()->assertSee('tidak bisa ditutup');
        $this->assertSame(WorkOrderStatus::Ready, $wo->fresh()->status);
    }

    public function test_name_guard_rejects_mismatch(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(customerName: 'Budi Santoso');

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Nama Salah',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ]);

        $res->assertOk()->assertSee('tidak cocok');
        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_name_guard_accepts_case_and_spacing_differences(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(customerName: 'Budi Santoso');

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => '  budi   santoso ',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ])->assertRedirect();

        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);
    }

    public function test_empty_signature_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => '',
            'photos' => $this->sixPhotos(),
        ])->assertSessionHasErrors('signature');

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_non_png_signature_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => 'data:image/png;base64,'.base64_encode('ini-bukan-png-sama-sekali'),
            'photos' => $this->sixPhotos(),
        ]);

        $res->assertOk()->assertSee('Tanda tangan tidak valid');
        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_oversized_signature_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();

        $res = $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => 'data:image/png;base64,'.base64_encode(str_repeat('A', 2_100_000)),
            'photos' => $this->sixPhotos(),
        ]);

        $res->assertOk()->assertSee('terlalu besar');
        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_missing_photo_type_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();
        $photos = $this->sixPhotos();
        unset($photos[WorkOrderPhotoType::OdpBox->value]); // hilangkan 1 tipe

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $photos,
        ])->assertSessionHasErrors('photos.'.WorkOrderPhotoType::OdpBox->value);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_webp_photo_is_rejected(): void
    {
        // v0.13.4.3.2 — WebP tidak lagi diterima (gagal di whitelist media WA).
        [$wo, $tech] = $this->makeWorkOrder();
        $photos = $this->sixPhotos();
        $photos[WorkOrderPhotoType::Speedtest->value] = UploadedFile::fake()->create('foto.webp', 50, 'image/webp');

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $photos,
        ])->assertSessionHasErrors('photos.'.WorkOrderPhotoType::Speedtest->value);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_svg_photo_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();
        $photos = $this->sixPhotos();
        $photos[WorkOrderPhotoType::Speedtest->value] = UploadedFile::fake()->createWithContent(
            'evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $photos,
        ])->assertSessionHasErrors('photos.'.WorkOrderPhotoType::Speedtest->value);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_non_image_photo_is_rejected(): void
    {
        [$wo, $tech] = $this->makeWorkOrder();
        $photos = $this->sixPhotos();
        // File non-gambar (ekstensi di luar mimes:jpg,jpeg,png) ditolak.
        // Catatan: UploadedFile::fake() menebak MIME dari EKSTENSI (bukan isi),
        // jadi kasus ".png berisi teks" diuji di jalur produksi (finfo konten),
        // bukan di sini — di sini cukup buktikan ekstensi/tipe non-gambar ditolak.
        $photos[WorkOrderPhotoType::Speedtest->value] = UploadedFile::fake()->createWithContent('catatan.txt', 'bukan gambar');

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $photos,
        ])->assertSessionHasErrors('photos.'.WorkOrderPhotoType::Speedtest->value);

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_happy_path_completes_and_stores_signature_privately(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(customerName: 'Budi Santoso');

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ])->assertRedirect();

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $wo->status);
        $this->assertNotNull($wo->technician_confirmed_at);
        $this->assertSame(6, $wo->photos()->count());

        $sig = WorkOrderSignature::where('work_order_id', $wo->id)->first();
        $this->assertNotNull($sig);
        // v0.19.1 wiring: terms_version = nomor versi installation_terms
        // aktif (seed v1 idempoten), snapshot = TEKS TER-RENDER (bukan
        // DEFAULT_CLOSE_TERMS), tanpa placeholder {..} mentah.
        $this->assertSame('1', $sig->terms_version);
        $this->assertNotEmpty($sig->terms_snapshot);
        $this->assertStringContainsString('SYARAT DAN KETENTUAN', $sig->terms_snapshot);
        $this->assertDoesNotMatchRegularExpression('/\{[a-zA-Z0-9_]+\}/', $sig->terms_snapshot);
        Storage::disk('local')->assertExists($sig->file_path);
        // Tanda tangan di disk privat, bukan path publik.
        $this->assertStringStartsWith('work-order-signatures/', $sig->file_path);
    }

    public function test_second_submit_after_completed_is_idempotent(): void
    {
        [$wo, $tech] = $this->makeWorkOrder(customerName: 'Budi Santoso');

        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);

        // Submit kedua -> kartu status SELESAI (seragam v0.13.4.5), bukan
        // error/500 dan bukan proses ulang.
        $this->post($this->showUrl($wo, $tech), [
            'customer_name' => 'Budi Santoso',
            'signature' => $this->signatureDataUri(),
            'photos' => $this->sixPhotos(),
        ])->assertOk()->assertSee('Work Order Selesai');

        $this->assertSame(1, WorkOrderSignature::where('work_order_id', $wo->id)->count());
    }
}
