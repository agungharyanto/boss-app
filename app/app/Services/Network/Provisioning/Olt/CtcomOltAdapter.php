<?php

namespace App\Services\Network\Provisioning\Olt;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use App\Models\Customer;
use App\Models\OltDevice;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;
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
 * v0.23.8.1 = SKELETON: admisi/naming/save diisi v0.23.8.2 (dipecah dari
 * CtcomActivationService). Nol tulis device.
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

    public function admit(OltDevice $olt, array $context = []): array
    {
        $this->pending('admit');
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

    private function pending(string $operation): never
    {
        throw new AdapterNotYetImplemented(
            "CtcomOltAdapter::{$operation}() ({$this->vendorKey}) akan diimplementasi v0.23.8.5 "
            .'(admisi: G02ID auto / E04ID bind-onu+confirm — butuh orkestrator device).'
        );
    }
}
