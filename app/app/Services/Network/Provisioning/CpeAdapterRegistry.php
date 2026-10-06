<?php

namespace App\Services\Network\Provisioning;

use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use App\Services\Network\Provisioning\Cpe\CtcomCpeAdapter;
use App\Services\Network\Provisioning\Cpe\UnsupportedCpeAdapter;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;

/**
 * Registry adapter SISI-CPE (per MEREK CPE) — KODE = sumber kebenaran
 * (keputusan #1 ZTP v0.23.8, pola OnuRegistryService::SUPPORTED_VENDOR_OPERATIONS).
 * Merek CPE diturunkan dari data-model TR-069 / OUI `cpe_devices`:
 *   ctcom       → CMDC / CT-COM (DIDUKUNG penuh, lintas semua OLT)
 *   huawei/cmcc/zte_generic → stub belum didukung
 */
final class CpeAdapterRegistry
{
    /**
     * vendor_key (merek) → factory(vendor_key): CpeProvisioningAdapter.
     *
     * @var array<string, callable(string): CpeProvisioningAdapter>
     */
    private array $map;

    public function __construct()
    {
        $this->map = [
            'ctcom' => fn (string $k): CpeProvisioningAdapter => new CtcomCpeAdapter($k),
            'huawei' => fn (string $k): CpeProvisioningAdapter => new UnsupportedCpeAdapter($k, 'Huawei'),
            'cmcc' => fn (string $k): CpeProvisioningAdapter => new UnsupportedCpeAdapter($k, 'CMCC'),
            'zte_generic' => fn (string $k): CpeProvisioningAdapter => new UnsupportedCpeAdapter($k, 'ZTE (generic TR-069)'),
        ];
    }

    public function for(string $vendorKey): CpeProvisioningAdapter
    {
        $factory = $this->map[$vendorKey] ?? throw new UnsupportedAdapterOperation(
            "Tidak ada CPE adapter terdaftar untuk merek \"{$vendorKey}\"."
        );

        return $factory($vendorKey);
    }

    /**
     * @return list<string>
     */
    public function knownVendorKeys(): array
    {
        return array_keys($this->map);
    }

    /**
     * @return array<string, CpeProvisioningAdapter>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->map as $vendorKey => $factory) {
            $out[$vendorKey] = $factory($vendorKey);
        }

        return $out;
    }
}
