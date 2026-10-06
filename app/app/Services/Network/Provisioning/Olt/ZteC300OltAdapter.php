<?php

namespace App\Services\Network\Provisioning\Olt;

use App\Enums\PonMode;
use App\Enums\ProvisioningAdapterLayer;
use App\Models\OltDevice;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\AdapterNotYetImplemented;

/**
 * OLT adapter ZTE C300 (GPON) — WAN internet via OMCI (tcont/gemport/
 * service-port/flow, section A + zte-c300-cli-reference.md), admisi
 * `uncfg`→config, penamaan `interface gpon-onu name/description`, save
 * auto-write (tidak perlu `copy run start` → capability save=false).
 *
 * MASUK v0.23.8 (bukan backlog) — keputusan revisi dua-dimensi (G5). SSID CPE
 * CMDC di OLT ini ditangani CpeProvisioningAdapter (TR-069, terbukti reachable).
 *
 * v0.23.8.1 = SKELETON: body operasi diisi v0.23.8.2b. Nol tulis device.
 */
final class ZteC300OltAdapter implements OltProvisioningAdapter
{
    public function __construct(private readonly string $vendorKey = 'zte_c300') {}

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
        return PonMode::Gpon;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            operations: [
                'admit' => true,
                'wan_internet_omci' => true,
                'naming' => true,
                'save' => false, // ZTE C300 auto-write
            ],
            wanInternetVia: 'omci',
        );
    }

    public function admit(OltDevice $olt, array $context = []): array
    {
        $this->pending('admit');
    }

    public function pushWanInternetOmci(OltDevice $olt, array $spec): array
    {
        $this->pending('pushWanInternetOmci');
    }

    public function applyNaming(OltDevice $olt, array $spec): array
    {
        $this->pending('applyNaming');
    }

    public function saveConfig(OltDevice $olt): array
    {
        // ZTE C300 auto-write — tidak ada operasi save eksplisit.
        return ['saved' => true, 'auto_write' => true];
    }

    private function pending(string $operation): never
    {
        throw new AdapterNotYetImplemented(
            "ZteC300OltAdapter::{$operation}() akan diimplementasi v0.23.8.2b "
            .'(WAN internet OMCI tcont/gemport/service-port) — belum ada logika tulis device.'
        );
    }
}
