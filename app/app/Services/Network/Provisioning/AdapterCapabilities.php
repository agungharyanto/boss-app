<?php

namespace App\Services\Network\Provisioning;

/**
 * Deskriptor kapabilitas sebuah adapter ZTP (v0.23.8) — untuk matriks
 * dukungan di UI + keputusan orkestrator (mis. WAN internet lewat OMCI vs
 * TR-069 device-driven). Di-serialize ke kolom `capabilities` tabel mirror
 * `provisioning_adapters`.
 */
final class AdapterCapabilities
{
    /**
     * @param  array<string, bool>  $operations  peta operasi → didukung (mis.
     *                                           ['admit'=>true,'ssid'=>true]).
     * @param  ?string  $wanInternetVia  khusus OLT adapter: 'omci' |
     *                                   'tr069_device_driven' | 'none'.
     * @param  list<string>  $untestedOnHardware  operasi/mode yang skema+UI-nya
     *                                            dibangun TAPI tulis-ke-device
     *                                            belum diverifikasi (keputusan
     *                                            #4 — mis. 'wan_mode:dhcp').
     */
    public function __construct(
        public readonly array $operations,
        public readonly ?string $wanInternetVia = null,
        public readonly array $untestedOnHardware = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operations' => $this->operations,
            'wan_internet_via' => $this->wanInternetVia,
            'untested_on_hardware' => $this->untestedOnHardware,
        ];
    }
}
