<?php

namespace App\Services\Network\Provisioning\Cpe;

use App\Enums\ProvisioningAdapterLayer;
use App\Models\CpeDevice;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;

/**
 * CPE adapter MEREK CMDC / CT-COM (TR-069) — DIDUKUNG, dipakai LINTAS semua
 * OLT (G02ID/E04ID/ZTE C300; terbukti TR-069 reachable di ketiganya, section
 * G1 docs/omci/ztp-dynamic-config-design.md).
 *
 * v0.23.8.1 = SKELETON (pondasi): kapabilitas/registry/mirror sudah bisa
 * dites, TAPI body operasi (WAN internet device-driven, bridge, SSID AddObject)
 * diisi v0.23.8.2 — dipecah dari CtcomActivationService + default-wan.js. Nol
 * tulis device di sub-versi ini.
 */
final class CtcomCpeAdapter implements CpeProvisioningAdapter
{
    public function __construct(private readonly string $vendorKey = 'ctcom') {}

    public function vendorKey(): string
    {
        return $this->vendorKey;
    }

    public function layer(): ProvisioningAdapterLayer
    {
        return ProvisioningAdapterLayer::Cpe;
    }

    public function transport(): string
    {
        return 'tr069';
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function capabilities(): AdapterCapabilities
    {
        // admit=false: admisi ONU ditangani OLT adapter, bukan CPE.
        return new AdapterCapabilities([
            'admit' => false,
            'wan_internet' => true,
            'wan_bridge' => true,
            'ssid' => true,
            'verify' => true,
        ]);
    }

    public function admit(CpeDevice $cpe, array $context = []): array
    {
        $this->pending('admit');
    }

    public function pushWanInternet(CpeDevice $cpe, array $spec): array
    {
        $this->pending('pushWanInternet');
    }

    public function pushWanBridge(CpeDevice $cpe, array $spec): array
    {
        $this->pending('pushWanBridge');
    }

    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array
    {
        $this->pending('pushSsid');
    }

    public function verify(CpeDevice $cpe, array $expectation = []): array
    {
        $this->pending('verify');
    }

    private function pending(string $operation): never
    {
        throw new AdapterNotYetImplemented(
            "CtcomCpeAdapter::{$operation}() akan diimplementasi v0.23.8.2 "
            .'(dipecah dari CtcomActivationService + default-wan.js) — belum ada logika tulis device.'
        );
    }
}
