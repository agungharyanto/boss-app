<?php

namespace App\Services\Network\Provisioning\Contracts;

use App\Models\CpeDevice;

/**
 * Adapter SISI-CPE (TR-069, per MEREK CPE) — SATU implementasi per merek
 * dipakai LINTAS semua OLT (section G docs/omci/ztp-dynamic-config-design.md).
 * CMDC/CT-COM diimplementasi penuh (mulai v0.23.8.2); Huawei/CMCC/ZTE-generic
 * = stub belum didukung.
 *
 * Catatan: `admit()` dicantumkan sesuai spesifikasi interface, TAPI admisi ONU
 * sejatinya operasi sisi-OLT (lihat OltProvisioningAdapter) — pada CPE adapter
 * umumnya no-op/delegasi; dipertegas saat implementasi v0.23.8.2.
 *
 * @param  array<string, mixed>  ...spec/context (bentuk konkret ditetapkan saat
 *                               template ZTP dibangun, v0.23.8.3/.4)
 */
interface CpeProvisioningAdapter extends ProvisioningAdapter
{
    /** @return array<string, mixed> */
    public function admit(CpeDevice $cpe, array $context = []): array;

    /** @return array<string, mixed> */
    public function pushWanInternet(CpeDevice $cpe, array $spec): array;

    /** @return array<string, mixed> */
    public function pushWanBridge(CpeDevice $cpe, array $spec): array;

    /**
     * @param  array<int, array<string, mixed>>  $ssidSpecs
     * @return array<string, mixed>
     */
    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array;

    /** @return array<string, mixed> */
    public function verify(CpeDevice $cpe, array $expectation = []): array;
}
