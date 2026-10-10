<?php

namespace App\Services\Network\Provisioning\Olt;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use App\Models\Customer;
use App\Models\OltDevice;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;
use RuntimeException;

/**
 * OLT adapter CT-COM OLT (HSGQ G02ID = GPON, HSGQ E04ID = EPON) — satu class
 * melayani KEDUA vendor key (di-instansiasi registry dengan vendorKey spesifik;
 * pon_mode diturunkan darinya). Operasi sisi-OLT: admisi (E04ID bind-onu+confirm;
 * G02ID auto-authorize), penamaan OMCI/CLI, save (`copy running-config
 * startup-config`).
 *
 * WAN internet CT-COM BUKAN via OMCI (device-driven TR-069 → CpeProvisioningAdapter),
 * jadi `wanInternetVia='tr069_device_driven'` + `pushWanInternetOmci()` menolak.
 *
 * Admisi (v0.23.8 LANGKAH D) membaca KOLOM `olt_devices.requires_bind_onu`
 * (per-device), BUKAN vendor key — true→bind-onu+confirm (E04ID), false→no-op
 * (G02ID), null→tolak eksplisit. Device verifikasi end-to-end = v0.23.8.5b.
 */
final class CtcomOltAdapter implements OltProvisioningAdapter
{
    public function __construct(private readonly string $vendorKey) {}

    public function vendorKey(): string
    {
        return $this->vendorKey;
    }

    public function layer(): ProvisioningAdapterLayer
    {
        return ProvisioningAdapterLayer::Olt;
    }

    public function transport(): string
    {
        return 'omci_cli';
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function ponMode(): PonMode
    {
        return PonMode::fromVendorKey($this->vendorKey);
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            operations: [
                'admit' => true,
                'wan_internet_omci' => false,
                'naming' => true,
                'save' => true,
            ],
            wanInternetVia: 'tr069_device_driven',
        );
    }

    /**
     * Admisi ONU — mode dibaca dari KOLOM `olt_devices.requires_bind_onu`
     * (PER-DEVICE, bukan hardcode vendor — keputusan LANGKAH D REVISI):
     *   true  → EPON deny-by-default (E04ID): bind-onu + onu confirm via sidecar
     *           (butuh $context: pon, mac, onu_id; opsional name/desc).
     *   false → auto-authorize (G02ID GPON): no-op sukses, ONU online sendiri.
     *   null  → mode BELUM ditentukan → tolak EKSPLISIT (jangan diam-diam
     *           pilih default — admin wajib set kolomnya lebih dulu).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function admit(OltDevice $olt, array $context = []): array
    {
        $requires = $olt->requires_bind_onu;

        if ($requires === null) {
            throw new RuntimeException(
                "OLT #{$olt->id} ({$this->vendorKey}) belum diset requires_bind_onu — "
                .'admission mode tidak terdefinisi. Set true (EPON: butuh bind-onu+confirm) '
                .'atau false (GPON: auto-authorize) sebelum aktivasi.'
            );
        }

        if ($requires === false) {
            return [
                'admitted' => true,
                'mode' => 'auto_authorize',
                'action' => 'none',
            ];
        }

        // true → bind-onu + onu confirm (E04ID). Butuh pon/mac/onu_id dari context.
        $params = $this->bindParams($context);
        $result = $this->sidecar()->bindOnu($olt, $params, $context['requested_by'] ?? null);

        if (($result['success'] ?? false) !== true) {
            $msg = $result['error'] ?? $result['device_message'] ?? 'alasan tak diketahui';
            throw new RuntimeException("Admisi bind-onu E04ID gagal (OLT #{$olt->id}): {$msg}");
        }

        return [
            'admitted' => true,
            'mode' => 'bind_onu',
            'pon' => $params['pon'],
            'onu_id' => $params['onu_id'],
            'result' => $result['data'] ?? null,
        ];
    }

    /**
     * Validasi + rakit params bind-onu dari $context admisi.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function bindParams(array $context): array
    {
        foreach (['pon', 'mac', 'onu_id'] as $key) {
            if (! isset($context[$key]) || $context[$key] === '') {
                throw new RuntimeException(
                    "Admisi bind-onu ({$this->vendorKey}) butuh context['{$key}'] (pon/mac/onu_id)."
                );
            }
        }

        return array_filter([
            'pon' => (int) $context['pon'],
            'mac' => (string) $context['mac'],
            'onu_id' => (int) $context['onu_id'],
            'name' => $context['name'] ?? null,
            'desc' => $context['desc'] ?? null,
        ], fn ($v) => $v !== null);
    }

    public function pushWanInternetOmci(OltDevice $olt, array $spec): array
    {
        throw new UnsupportedAdapterOperation(
            'WAN internet CT-COM OLT bersifat device-driven via TR-069 (bukan OMCI) — '
            .'gunakan CpeProvisioningAdapter::pushWanInternet().'
        );
    }

    /**
     * Penamaan ONU "Nama - CID". Delegasi ke CtcomActivationService::
     * applyOmciNaming (resolveOnuBySn + set_ont_naming sidecar). `$spec` wajib
     * membawa `customer` (Customer) + `sn` (string).
     *
     * CATATAN E04ID: applyOmciNaming saat ini memakai `set_ont_naming` (OMCI
     * G02ID, resolve gpon 1/2). Penamaan E04ID lewat `onu description <id>`
     * (CLI, terbukti manual v0.23.7) BELUM punya operasi sidecar tersendiri —
     * di-wire saat orkestrator device v0.23.8.5. Untuk G02ID (GPON) delegasi
     * ini sudah penuh & teruji.
     */
    public function applyNaming(OltDevice $olt, array $spec): array
    {
        $customer = $spec['customer'] ?? null;
        $sn = $spec['sn'] ?? null;
        if (! $customer instanceof Customer || ! is_string($sn) || $sn === '') {
            throw new RuntimeException('CtcomOltAdapter::applyNaming butuh spec["customer"] (Customer) + spec["sn"] (string).');
        }

        return $this->service()->applyOmciNaming($olt, $customer, $sn, $spec['requested_by'] ?? null);
    }

    public function saveConfig(OltDevice $olt): array
    {
        return $this->service()->saveConfig($olt, null);
    }

    private function service(): CtcomActivationService
    {
        return app(CtcomActivationService::class);
    }

    private function sidecar(): OltSidecarClient
    {
        return app(OltSidecarClient::class);
    }
}
