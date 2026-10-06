<?php

namespace App\Services\Network\Provisioning\Olt;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use App\Models\OltDevice;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;

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

    public function applyNaming(OltDevice $olt, array $spec): array
    {
        $this->pending('applyNaming');
    }

    public function saveConfig(OltDevice $olt): array
    {
        $this->pending('saveConfig');
    }

    private function pending(string $operation): never
    {
        throw new AdapterNotYetImplemented(
            "CtcomOltAdapter::{$operation}() ({$this->vendorKey}) akan diimplementasi v0.23.8.2 "
            .'(dipecah dari CtcomActivationService) — belum ada logika tulis device.'
        );
    }
}
