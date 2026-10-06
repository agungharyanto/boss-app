<?php

namespace App\Services\Network\Provisioning\Cpe;

use App\Enums\ProvisioningAdapterLayer;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;
use RuntimeException;

/**
 * CPE adapter MEREK CMDC / CT-COM (TR-069) — DIDUKUNG, dipakai LINTAS semua
 * OLT (G02ID/E04ID/ZTE C300; terbukti TR-069 reachable di ketiganya, section
 * G1 docs/omci/ztp-dynamic-config-design.md).
 *
 * v0.23.8.2 — wrapper tipis di atas CtcomActivationService (sumber logika,
 * tes coverage existing-nya dipertahankan): WAN internet device-driven +
 * verifikasi WAN bridge. `pushSsid` masih skeleton (logika SSID AddObject ada
 * di default-wan.js/ad-hoc v0.23.7, belum ada method PHP untuk dipecah —
 * di-wire saat template SSID + orkestrator dibangun, v0.23.8.4/.5).
 *
 * Customer + genieacs_device_id diturunkan dari CpeDevice; `$spec` membawa
 * override opsional (mis. `requested_by`, `bridge_vlan`). Bentuk `$spec` final
 * ditetapkan saat template ZTP (v0.23.8.3/.4).
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
        return new AdapterCapabilities([
            'wan_internet' => true,
            'wan_bridge' => true,
            'ssid' => true,
            'verify' => true,
        ]);
    }

    public function pushWanInternet(CpeDevice $cpe, array $spec): array
    {
        return $this->service()->pushNetworkPolicy(
            $this->resolveCustomer($cpe),
            $this->resolveGenieAcsDeviceId($cpe),
            $spec['requested_by'] ?? null,
        );
    }

    public function pushWanBridge(CpeDevice $cpe, array $spec): array
    {
        // Bridge CT-COM bersifat device-driven (CPE membentuk sendiri) — adapter
        // VERIFIKASI keberadaannya, tidak mem-push dari nol. (Eksperimen push
        // bridge via TR-069 untuk ZTE C300 = v0.23.8.5, section G3.)
        $bridgeVlan = (int) ($spec['bridge_vlan'] ?? 172);
        $present = $this->service()->verifyBridgePresent($this->resolveGenieAcsDeviceId($cpe), $bridgeVlan);

        return ['bridge_present' => $present, 'bridge_vlan' => $bridgeVlan, 'device_driven' => true];
    }

    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array
    {
        throw new AdapterNotYetImplemented(
            'CtcomCpeAdapter::pushSsid() akan diimplementasi v0.23.8.4/.5 '
            .'(AddObject sekuensial + resep open/wpa2, di-drive template SSID) — belum ada spec SSID.'
        );
    }

    public function verify(CpeDevice $cpe, array $expectation = []): array
    {
        $bridgeVlan = (int) ($expectation['bridge_vlan'] ?? 172);

        return [
            'bridge_present' => $this->service()->verifyBridgePresent($this->resolveGenieAcsDeviceId($cpe), $bridgeVlan),
        ];
    }

    private function service(): CtcomActivationService
    {
        return app(CtcomActivationService::class);
    }

    private function resolveCustomer(CpeDevice $cpe): Customer
    {
        $customer = $cpe->loadMissing('customer')->customer;
        if ($customer === null) {
            throw new RuntimeException("CpeDevice #{$cpe->id} tidak punya customer tertaut — tak bisa derive kredensial WAN.");
        }

        return $customer;
    }

    private function resolveGenieAcsDeviceId(CpeDevice $cpe): string
    {
        $id = $cpe->genieacs_device_id;
        if ($id === null || $id === '') {
            throw new RuntimeException("CpeDevice #{$cpe->id} belum punya genieacs_device_id — CPE belum Inform ke GenieACS.");
        }

        return $id;
    }
}
