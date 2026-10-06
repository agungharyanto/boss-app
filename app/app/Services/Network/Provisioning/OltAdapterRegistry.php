<?php

namespace App\Services\Network\Provisioning;

use App\Services\Network\Provisioning\Contracts\OltProvisioningAdapter;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;
use App\Services\Network\Provisioning\Olt\CtcomOltAdapter;
use App\Services\Network\Provisioning\Olt\ZteC300OltAdapter;

/**
 * Registry adapter SISI-OLT (per vendor OLT) — KODE = sumber kebenaran
 * (keputusan #1 ZTP v0.23.8). Vendor key dari OltDevice::sidecarVendorKey():
 *   hsgq_g02id / hsgq_e04id → CtcomOltAdapter (satu class, pon_mode per key)
 *   zte_c300                → ZteC300OltAdapter
 */
final class OltAdapterRegistry
{
    /**
     * @var array<string, callable(string): OltProvisioningAdapter>
     */
    private array $map;

    public function __construct()
    {
        $this->map = [
            'hsgq_g02id' => fn (string $k): OltProvisioningAdapter => new CtcomOltAdapter($k),
            'hsgq_e04id' => fn (string $k): OltProvisioningAdapter => new CtcomOltAdapter($k),
            'zte_c300' => fn (string $k): OltProvisioningAdapter => new ZteC300OltAdapter($k),
        ];
    }

    public function for(string $vendorKey): OltProvisioningAdapter
    {
        $factory = $this->map[$vendorKey] ?? throw new UnsupportedAdapterOperation(
            "Tidak ada OLT adapter terdaftar untuk vendor \"{$vendorKey}\"."
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
     * @return array<string, OltProvisioningAdapter>
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
