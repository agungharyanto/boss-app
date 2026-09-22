<?php

namespace App\Enums;

/**
 * Status SINKRONISASI BOSS sendiri — KONSEP TERPISAH dari
 * OnuRegistryStatus (status ONU itu sendiri menurut OLT). Registry ONU
 * (v0.23.4) sinkron ON-DEMAND SAJA, tidak ada job berkala — kolom ini
 * murni hasil percobaan sync EKSPLISIT terakhir, tidak pernah diisi
 * otomatis berdasarkan waktu berlalu.
 *
 * Catatan jujur soal `NeverSynced`: dalam desain App\Services\Network\
 * OnuRegistryService::syncOnu() SEKARANG, sebuah baris `onu_registries`
 * HANYA PERNAH dibuat lewat sync yang BERHASIL — jadi secara PRAKTIK,
 * `NeverSynced` tidak pernah genuinely tersimpan untuk baris yang ada di
 * database (baris baru selalu langsung `Synced`). Case ini tetap
 * disediakan untuk (a) nilai default kolom di level DB sebagai
 * safety-net murni, dan (b) future-proofing kalau kelak ada jalur
 * pembuatan baris registry tanpa sync sukses (mis. pre-seed dari daftar
 * `uncfg` tanpa detail penuh) — bukan diam-diam dihilangkan.
 */
enum OnuSyncStatus: string
{
    case NeverSynced = 'never_synced';

    // Sync terakhir untuk baris ini berhasil — `status`/`serial_number`/
    // `name`/dll di baris yang sama merepresentasikan hasil sync itu.
    case Synced = 'synced';

    // Baris sudah ada dari sync SEBELUMNYA yang berhasil, tapi percobaan
    // sync BERIKUTNYA gagal (mis. OLT tidak terjangkau, sidecar timeout)
    // — data ONU di baris ini (status/serial_number/dll) SENGAJA
    // dipertahankan apa adanya (bukan dihapus/dikosongkan), tapi
    // ditandai `stale` sebagai sinyal "mungkin sudah tidak akurat".
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::NeverSynced => 'Belum Pernah Disinkron',
            self::Synced => 'Tersinkron',
            self::Stale => 'Basi (percobaan sync terakhir gagal)',
        };
    }
}
