<?php

namespace App\Services\Network\Provisioning\Contracts;

use App\Models\CpeDevice;

/**
 * Adapter SISI-CPE (TR-069, per MEREK CPE) — SATU implementasi per merek
 * dipakai LINTAS semua OLT (section G docs/omci/ztp-dynamic-config-design.md).
 * CMDC/CT-COM diimplementasi penuh (mulai v0.23.8.2); Huawei/CMCC/ZTE-generic
 * = stub belum didukung.
 *
 * Admisi ONU SENGAJA TIDAK ada di interface ini — murni operasi sisi-OLT
 * (lihat OltProvisioningAdapter::admit). CPE adapter hanya menangani operasi
 * TR-069 sisi-perangkat: WAN internet device-driven, WAN bridge, SSID.
 *
 * @param  array<string, mixed>  ...spec/context (bentuk konkret ditetapkan saat
 *                               template ZTP dibangun, v0.23.8.3/.4)
 */
interface CpeProvisioningAdapter extends ProvisioningAdapter
{
    /** @return array<string, mixed> */
    public function pushWanInternet(CpeDevice $cpe, array $spec): array;

    /** @return array<string, mixed> */
    public function pushWanBridge(CpeDevice $cpe, array $spec): array;

    /**
     * @param  array<int, array<string, mixed>>  $ssidSpecs
     * @return array<string, mixed>
     */
    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array;

    /**
     * Matikan (Enable=false) instance WLAN yang ADA di device tapi TIDAK dipakai:
     * tidak tercakup SSID profil (`$ssidSpecs`) DAN tidak di `X_CT-COM_LanInterface`
     * WAN mana pun. Dijalankan SETELAH pushSsid + pushWanBridge.
     *
     * @param  array<int, array<string, mixed>>  $ssidSpecs  spec SSID profil (role/band) — penentu "terpakai"
     * @return array<string, mixed>
     */
    public function disableUnboundWlan(CpeDevice $cpe, array $ssidSpecs): array;

    /** @return array<string, mixed> */
    public function verify(CpeDevice $cpe, array $expectation = []): array;
}
