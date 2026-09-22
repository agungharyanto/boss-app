<?php

namespace App\Enums;

/**
 * Status ONU itu sendiri menurut OLT — KONSEP TERPISAH dari
 * OnuSyncStatus (status sinkronisasi BOSS sendiri, lihat enum itu).
 *
 * Setiap case di sini punya BUKTI NYATA dari sesi riset CLI v0.23.1/
 * v0.23.2 (docs/omci/zte-c300-cli-reference.md, hsgq-*-cli-reference.md)
 * — TIDAK ADA yang dikarang. Lihat docs/omci/onu-registry-design.md §5
 * untuk mapping detail per vendor, termasuk penyesuaian yang dilaporkan
 * eksplisit ke Agung (draft awal "pending" diganti "offline" karena tidak
 * ada bukti "pending" di dokumen manapun, sedangkan "offline" punya bukti
 * nyata langsung).
 */
enum OnuRegistryStatus: string
{
    // ZTE: detail-info/baseinfo dengan Phase state=working atau
    // State=ready (ONU_UJI_1/2, Sesi 5-7 zte-c300-cli-reference.md).
    case Active = 'active';

    // ZTE: Admin State=enable, OMCC State=disable, Phase State=OffLine
    // (ONU lain yang terdaftar tapi tidak online, Sesi 5).
    case Offline = 'offline';

    // ZTE: muncul di `show gpon onu uncfg` (State field device sendiri:
    // "unknown", tapi konteks command = belum dikonfigurasi).
    case Unconfigured = 'unconfigured';

    // G02ID: muncul di `show black-ont` ("Ont deny list") — belum ada
    // bukti state setara di ZTE/E04ID, case ini disiapkan untuk G02ID
    // begitu operasi per-ONU-nya TERUJI (lihat backlog v0.23.6/v0.23.7).
    case Rejected = 'rejected';

    // Default/fallback — satu-satunya nilai yang mungkin untuk HSGQ
    // sampai operasi per-ONU-nya TERUJI, dan fallback aman untuk
    // kombinasi state ZTE yang belum pernah teramati.
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Offline => 'Offline',
            self::Unconfigured => 'Belum Dikonfigurasi',
            self::Rejected => 'Ditolak',
            self::Unknown => 'Tidak Diketahui',
        };
    }
}
