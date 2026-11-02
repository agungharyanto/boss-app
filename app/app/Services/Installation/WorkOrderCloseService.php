<?php

namespace App\Services\Installation;

use App\Enums\CpeDeviceStatus;
use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderCloseException;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Models\WorkOrderSignature;
use App\Services\Company\CompanyProfileService;
use App\Services\Company\InstallationTermsService;
use App\Services\Company\TermsRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v0.13.4.3.1 — penutupan WO oleh teknisi lewat FORM WEB (signed-link),
 * tahap-3 PSB setelah klaim (v0.13.4.1) → aktivasi (v0.13.4.2.2, WO
 * InProgress) → tutup WO (ini).
 *
 * GERBANG KERAS khusus jalur web (keputusan Agung, 2026-11-01): ONU online
 * DAN WiFi terkirim WAJIB terpenuhi sebelum submit diterima SERVER. Jalur
 * admin/API `POST /work-orders/{wo}/complete` TIDAK digating ONU — itu jalan
 * keluar bila ONU bermasalah. "ONU online" = `cpe_devices.status = online`
 * (di-set bersamaan dengan `genieacs_device_id` oleh
 * CpeBindingService::attributesFromGenieAcsDevice()), "WiFi terkirim" =
 * `cpe_devices.wifi_provisioned_at` terisi (di-set
 * CpeBindingService::provisionWifiIfPending()). Konsisten dengan
 * WorkOrderActivationService::statusFor().
 *
 * Konfirmasi di form: (a) teknisi mengetik nama pelanggan (guard sama
 * aktivasi: WorkOrderActivationService::normalizeName) + (b) pelanggan tanda
 * tangan di layar. OTP WhatsApp TIDAK dipakai di jalur web (signature
 * signed-link + guard nama + tanda tangan = otorisasi); `technician_confirmed_at`
 * di-set via WorkOrderService::confirmByWebClose() lalu complete() yang sudah
 * ada dipanggil (jalur OTP API tetap utuh).
 */
class WorkOrderCloseService
{
    private const SIGNATURE_MAX_BYTES = 2_000_000;

    // Heuristik "tidak kosong" berbasis UKURAN BYTE (bukan analisis piksel —
    // GD/Imagick tidak terpasang di image boss-app). Kanvas kosong PNG
    // transparan biasanya < 1,5 KB; coretan nyata jauh lebih besar. Pixel-
    // accurate check = backlog bila GD/Imagick tersedia.
    private const SIGNATURE_MIN_BYTES = 1_800;

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly WorkOrderPhotoService $photos,
        private readonly InstallationTermsService $installationTerms,
        private readonly TermsRenderer $termsRenderer,
        private readonly CompanyProfileService $companyProfiles,
    ) {}

    /**
     * v0.19.1 wiring — teks persyaratan DINAMIS: versi aktif
     * `installation_terms` tenant (seed v1 idempoten bila belum ada, tidak
     * memblokir) di-render dengan konteks nyata (Profil Perusahaan + nominal
     * paket + pelanggan + tanggal). Dipakai halaman form (tampilan) DAN
     * snapshot tanda tangan — supaya teks yang ditandatangani = teks yang
     * ditampilkan.
     *
     * @return array{version: string, text: string}
     */
    public function renderedTerms(WorkOrder $workOrder): array
    {
        $customer = Customer::withoutGlobalScopes()->findOrFail($workOrder->customer_id);
        $terms = $this->installationTerms->activeOrSeedDefault($workOrder->tenant_id);

        return [
            'version' => (string) $terms->version,
            'text' => $this->termsRenderer->render($terms->body, $this->buildTermsContext($workOrder, $customer)),
        ];
    }

    /**
     * Konteks render. {biaya_bulanan} = SNAPSHOT `ppp_packages.sell_price`
     * saat ini (TANPA promo_price). Nomor CS pakai override reseller bila
     * pelanggan berreseller (CompanyProfileService::resolveFor). Profil
     * kosong → fallback jelas (tidak melempar, tidak memblokir).
     *
     * @return array<string, string>
     */
    private function buildTermsContext(WorkOrder $workOrder, Customer $customer): array
    {
        $company = $this->companyProfiles->resolveFor($workOrder->tenant_id, $customer->reseller_id);

        $package = $customer->ppp_package_id !== null
            ? PppPackage::withoutGlobalScopes()->find($customer->ppp_package_id)
            : null;

        $monthly = $package?->sell_price;

        return [
            'nama_perusahaan' => $company['legal_name'],
            'alamat_perusahaan' => $company['address'],
            'nomor_cs' => $company['cs'],
            'nama_pelanggan' => (string) ($customer->name ?? ''),
            'alamat_pelanggan' => (string) ($customer->address ?? ''),
            'nama_paket' => (string) ($package?->name ?? ''),
            'biaya_bulanan' => $monthly !== null ? number_format((float) $monthly, 0, ',', '.') : '',
            'tanggal' => now()->translatedFormat('d F Y'),
        ];
    }

    /**
     * State gerbang untuk halaman form (ONU online + WiFi terkirim).
     *
     * @return array{onu_online: bool, wifi_sent: bool, ready: bool}
     */
    public function gateState(WorkOrder $workOrder): array
    {
        $device = WorkOrderDevice::where('work_order_id', $workOrder->id)
            ->where('device_type', WorkOrderDeviceType::Ont)
            ->orderBy('id')
            ->first();

        $cpe = $device !== null
            ? CpeDevice::withoutGlobalScopes()->where('work_order_device_id', $device->id)->first()
            : null;

        $onuOnline = $cpe !== null && $cpe->status === CpeDeviceStatus::Online;
        $wifiSent = $cpe !== null && $cpe->wifi_provisioned_at !== null;

        return [
            'onu_online' => $onuOnline,
            'wifi_sent' => $wifiSent,
            'ready' => $onuOnline && $wifiSent,
        ];
    }

    /**
     * @throws WorkOrderCloseException
     */
    public function assertReadyForWebClose(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            throw WorkOrderCloseException::notInProgress($workOrder->status);
        }

        $gate = $this->gateState($workOrder);
        if (! $gate['ready']) {
            throw WorkOrderCloseException::onuNotReady($gate['onu_online'], $gate['wifi_sent']);
        }
    }

    /**
     * Tutup WO dari form web. Satu transaksi + lockForUpdate (kirim ulang/2 tab
     * aman). WO yang SUDAH Completed → kembalikan apa adanya (controller
     * menampilkan halaman status, bukan error).
     *
     * @param  array<string, UploadedFile>  $photos  keyed by WorkOrderPhotoType value
     *
     * @throws WorkOrderCloseException
     */
    public function closeFromForm(
        WorkOrder $workOrder,
        Technician $claimer,
        string $customerNameInput,
        array $photos,
        string $signatureBase64,
        ?string $ip,
        ?string $userAgent,
    ): WorkOrder {
        // Validasi tanda tangan + nama DI LUAR transaksi (murni in-memory),
        // supaya penolakan tidak membuka/roll back transaksi tanpa guna.
        $customer = Customer::withoutGlobalScopes()->findOrFail($workOrder->customer_id);
        if (WorkOrderActivationService::normalizeName($customerNameInput)
            !== WorkOrderActivationService::normalizeName((string) $customer->name)) {
            throw WorkOrderCloseException::customerNameMismatch();
        }

        $signaturePng = $this->decodeAndValidateSignature($signatureBase64);

        // Keenam tipe foto wajib hadir di input (complete() juga menegaskan
        // lewat assertReadyToComplete, ini pesan lebih dini + spesifik).
        $missing = [];
        foreach (WorkOrderPhotoType::cases() as $type) {
            if (! isset($photos[$type->value]) || ! $photos[$type->value] instanceof UploadedFile) {
                $missing[] = $type->label();
            }
        }
        if ($missing !== []) {
            throw WorkOrderCloseException::photosIncomplete('Foto belum lengkap — kurang: '.implode(', ', $missing).'.');
        }

        return DB::transaction(function () use ($workOrder, $claimer, $photos, $signaturePng, $ip, $userAgent) {
            $locked = WorkOrder::withoutGlobalScopes()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            // Idempoten: sudah ditutup → kembalikan apa adanya.
            if ($locked->status === WorkOrderStatus::Completed) {
                return $locked;
            }

            if ($locked->claimed_at === null
                || (int) $locked->claimed_by_technician_id !== (int) $claimer->id) {
                throw WorkOrderCloseException::notClaimedByActor();
            }

            $this->assertReadyForWebClose($locked);

            // Simpan 6 foto (disk privat, validasi mimes sudah di FormRequest).
            foreach (WorkOrderPhotoType::cases() as $type) {
                $this->photos->store($locked, $type, $photos[$type->value]);
            }

            // Simpan tanda tangan + snapshot persyaratan yang disetujui.
            // v0.19.1 wiring: snapshot = TEKS TER-RENDER (nama PT, nominal, CS
            // saat ini), terms_version = nomor versi installation_terms aktif.
            // Kolom lama work_order_dispatch_settings.close_terms_* +
            // DEFAULT_CLOSE_TERMS TIDAK dipakai lagi (deprecated, kolom
            // dibiarkan).
            $rendered = $this->renderedTerms($locked);
            $signaturePath = "work-order-signatures/{$locked->id}.png";
            Storage::disk('local')->put($signaturePath, $signaturePng);

            WorkOrderSignature::updateOrCreate(
                ['work_order_id' => $locked->id],
                [
                    'file_path' => $signaturePath,
                    'signed_at' => now(),
                    'terms_version' => $rendered['version'],
                    'terms_snapshot' => $rendered['text'],
                    'signed_ip' => $ip,
                    'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
                ],
            );

            // Konfirmasi jalur web (isi technician_confirmed_at tanpa OTP) lalu
            // complete() yang sudah ada (gerbang 6 foto + device + confirmed +
            // port->Used + best-effort bind/push).
            $this->workOrders->confirmByWebClose($locked);

            return $this->workOrders->complete($locked);
        });
    }

    /**
     * Decode data-URL/base64 PNG tanda tangan, validasi benar-benar PNG,
     * dimensi+ukuran wajar, dan tidak kosong (heuristik byte). GD/Imagick
     * tidak ada → pakai getimagesizefromstring() (independen GD) untuk tipe+
     * dimensi.
     *
     * @throws WorkOrderCloseException
     */
    private function decodeAndValidateSignature(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw WorkOrderCloseException::signatureMissing();
        }

        // Buang prefix data-URL kalau ada.
        if (str_contains($input, ',')) {
            $input = substr($input, strpos($input, ',') + 1);
        }

        $binary = base64_decode($input, true);
        if ($binary === false || $binary === '') {
            throw WorkOrderCloseException::signatureInvalid();
        }

        if (strlen($binary) > self::SIGNATURE_MAX_BYTES) {
            throw WorkOrderCloseException::signatureTooLarge();
        }

        // Magic bytes PNG.
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw WorkOrderCloseException::signatureInvalid();
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            throw WorkOrderCloseException::signatureInvalid();
        }
        [$width, $height] = $info;
        if ($width < 100 || $width > 3000 || $height < 40 || $height > 2000) {
            throw WorkOrderCloseException::signatureInvalid();
        }

        // Heuristik "tidak kosong" (lihat konstanta).
        if (strlen($binary) < self::SIGNATURE_MIN_BYTES) {
            throw WorkOrderCloseException::signatureInvalid();
        }

        return $binary;
    }
}
