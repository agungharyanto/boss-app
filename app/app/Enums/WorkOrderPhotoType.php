<?php

namespace App\Enums;

/**
 * v0.13.4.3.1 — 6 tipe foto wajib dokumentasi penutupan Work Order
 * (mengganti 4 tipe lama odp/ont_device/signal_strength/house_front —
 * `work_order_photos` kosong di produksi saat perubahan ini, tidak ada
 * data lama yang perlu dimigrasikan). Keenam tipe WAJIB di SEMUA jalur
 * penutupan (API admin maupun form web teknisi) — lihat
 * WorkOrderService::assertReadyToComplete().
 */
enum WorkOrderPhotoType: string
{
    case Speedtest = 'speedtest';
    case ModemLocation = 'modem_location';
    case CableRouteIkr = 'cable_route_ikr';
    case OdpPort = 'odp_port';
    case OdpBox = 'odp_box';
    case CustomerFront = 'customer_front';

    public function label(): string
    {
        return match ($this) {
            self::Speedtest => 'Foto Hasil Speedtest',
            self::ModemLocation => 'Foto Lokasi Modem',
            self::CableRouteIkr => 'Foto Jalur Kabel IKR',
            self::OdpPort => 'Foto Port ODP',
            self::OdpBox => 'Foto Box ODP',
            self::CustomerFront => 'Foto Tampak Depan Pelanggan',
        };
    }
}
