<?php

namespace App\Services\Network\Provisioning\Contracts;

use App\Enums\PonMode;
use App\Models\OltDevice;

/**
 * Adapter SISI-OLT (per vendor OLT) — admisi ONU, WAN internet via OMCI (ZTE
 * C300), penamaan, save config (section G docs/omci/ztp-dynamic-config-design.md).
 * WAN internet CT-COM-OLT (G02ID/E04ID) BUKAN via OMCI (device-driven TR-069 →
 * ditangani CpeProvisioningAdapter); `capabilities()->wanInternetVia` memberi
 * tahu orkestrator jalur mana yang dipakai.
 *
 * @param  array<string, mixed>  ...spec/context (bentuk konkret saat v0.23.8.2/.2b)
 */
interface OltProvisioningAdapter extends ProvisioningAdapter
{
    public function ponMode(): PonMode;

    /** @return array<string, mixed> */
    public function admit(OltDevice $olt, array $context = []): array;

    /** @return array<string, mixed> */
    public function pushWanInternetOmci(OltDevice $olt, array $spec): array;

    /** @return array<string, mixed> */
    public function applyNaming(OltDevice $olt, array $spec): array;

    /** @return array<string, mixed> */
    public function saveConfig(OltDevice $olt): array;
}
