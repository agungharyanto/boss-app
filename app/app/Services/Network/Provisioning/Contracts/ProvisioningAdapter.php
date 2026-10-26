<?php

namespace App\Services\Network\Provisioning\Contracts;

use App\Enums\ProvisioningAdapterLayer;
use App\Services\Network\Provisioning\AdapterCapabilities;

/**
 * Deskriptor umum semua adapter ZTP (v0.23.8) — dipakai registry + seeder
 * tabel mirror. Dua turunan: OltProvisioningAdapter & CpeProvisioningAdapter
 * (model dua-dimensi, section G docs/omci/ztp-dynamic-config-design.md).
 */
interface ProvisioningAdapter
{
    public function vendorKey(): string;

    public function layer(): ProvisioningAdapterLayer;

    /** 'tr069' | 'omci_cli' */
    public function transport(): string;

    public function isSupported(): bool;

    public function capabilities(): AdapterCapabilities;
}
