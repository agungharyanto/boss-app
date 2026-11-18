<?php

namespace App\Enums;

/**
 * v0.13.4.2.3 — status pemicu ZTP per WorkOrder (kolom work_orders.ztp_status).
 *   Pending  — job sudah di-antre, ONU belum ditemukan/diprovisioning.
 *   Running  — discovery/provision sedang berjalan.
 *   Success  — provision() ok (ONU dibuka jalur WAN-nya).
 *   Failed   — gagal final (discovery ganda/tak ketemu setelah retry, atau
 *              provision() gagal). Aktivasi TIDAK ikut gagal.
 *   Skipped  — tak dijalankan (kanal OLT disabled / gate OMCI off / tak ada OLT
 *              tenant yang cocok) — bukan error, cuma tak ada aksi.
 */
enum ZtpStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu ONU terdeteksi',
            self::Running => 'Provisioning OLT berjalan',
            self::Success => 'Provisioning OLT berhasil',
            self::Failed => 'Provisioning OLT gagal',
            self::Skipped => 'Provisioning OLT dilewati',
        };
    }
}
