<?php

namespace App\Services\Network\Provisioning\Cpe;

use App\Enums\ProvisioningAdapterLayer;
use App\Models\CpeDevice;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;

/**
 * Stub CPE adapter untuk merek yang belum didukung/diverifikasi
 * (Huawei/CMCC/ZTE-generic di v0.23.8). is_supported=false; semua operasi
 * melempar UnsupportedAdapterOperation dengan pesan jelas.
 */
final class UnsupportedCpeAdapter implements CpeProvisioningAdapter
{
    public function __construct(
        private readonly string $vendorKey,
        private readonly string $label,
    ) {}

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
        return false;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities([
            'wan_internet' => false,
            'wan_bridge' => false,
            'ssid' => false,
            'verify' => false,
        ]);
    }

    public function pushWanInternet(CpeDevice $cpe, array $spec): array
    {
        $this->reject('pushWanInternet');
    }

    public function pushWanBridge(CpeDevice $cpe, array $spec): array
    {
        $this->reject('pushWanBridge');
    }

    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array
    {
        $this->reject('pushSsid');
    }

    public function disableUnboundWlan(CpeDevice $cpe, array $ssidSpecs): array
    {
        $this->reject('disableUnboundWlan');
    }

    public function verify(CpeDevice $cpe, array $expectation = []): array
    {
        $this->reject('verify');
    }

    private function reject(string $operation): never
    {
        throw new UnsupportedAdapterOperation(
            "CPE adapter merek \"{$this->label}\" ({$this->vendorKey}) belum didukung/diverifikasi — "
            ."operasi \"{$operation}\" tidak tersedia (lihat docs/omci/ztp-dynamic-config-design.md)."
        );
    }
}
