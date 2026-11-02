<?php

namespace App\Services\Company;

use App\Models\InstallationTerms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v0.19.1 — accessor "versi aktif" + penulisan versi BARU (INSERT-ONLY).
 */
class InstallationTermsService
{
    /**
     * DRAFT v1 — 10 pasal, variabel whitelist terisi. Satu sumber kebenaran
     * (dipakai InstallationTermsSeeder DAN activeOrSeedDefault()).
     * {tanggal_jatuh_tempo} SENGAJA TIDAK dipakai (tak ada sumber andal) —
     * pasal 4 pakai "sesuai tanggal jatuh tempo pada tagihan". Agung
     * memfinalkan lewat editor (tiap simpan = versi baru).
     */
    public const DEFAULT_V1_BODY = <<<'TERMS'
        SYARAT DAN KETENTUAN PEMASANGAN LAYANAN INTERNET
        Dengan menandatangani, saya ({nama_pelanggan}, beralamat di {alamat_pelanggan}) selaku pelanggan menyatakan bahwa:
        1. Data diri dan alamat pemasangan yang tercantum adalah benar, dan saya berhak menyetujui pemasangan di lokasi tersebut.
        2. Pemasangan telah selesai dilakukan teknisi dan layanan berfungsi saat serah terima, termasuk hasil uji kecepatan yang ditunjukkan teknisi.
        3. Perangkat (modem/ONT dan kabel) yang terpasang adalah milik {nama_perusahaan} dan dipinjamkan selama saya berlangganan. Saya akan menjaganya dan tidak membongkar, memindahkan, atau memperjualbelikannya.
        4. Saya mengetahui paket layanan {nama_paket}. Biaya pemasangan dan biaya perangkat adalah sesuai yang disepakati pada pendaftaran. Biaya berlangganan adalah Rp {biaya_bulanan} per bulan, dan saya akan membayar tepat waktu sesuai tanggal jatuh tempo pada tagihan.
        5. Kecepatan pada paket adalah kecepatan maksimal ("hingga") dan dapat dipengaruhi kondisi jaringan, perangkat saya, serta WiFi di dalam rumah.
        6. Saya tidak akan memakai layanan untuk kegiatan yang melanggar hukum, dan tidak membagikan atau menjualnya kepada pihak lain tanpa izin tertulis.
        7. Gangguan layanan saya laporkan melalui Customer Service {nama_perusahaan} di {nomor_cs}, dan akan ditangani sesuai prosedur perusahaan.
        8. Nama dan kata sandi WiFi telah diserahkan kepada saya, dan saya bertanggung jawab menjaga kerahasiaannya.
        9. Saya menyetujui teknisi mengambil foto dokumentasi pemasangan (perangkat, jalur kabel, ODP, dan tampak depan lokasi) yang dipakai dan dibagikan kepada tim internal perusahaan untuk keperluan operasional dan penanganan gangguan, serta data saya diproses untuk keperluan layanan sesuai ketentuan perlindungan data pribadi.
        10. Tanda tangan ini pada tanggal {tanggal} adalah persetujuan saya atas seluruh poin di atas.
        TERMS;

    /**
     * Versi aktif = `version` tertinggi per tenant, atau null kalau belum
     * pernah ada (sebelum seed v1).
     */
    public function active(int $tenantId): ?InstallationTerms
    {
        return InstallationTerms::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Versi aktif, atau seed v1 DRAFT secara idempoten bila tenant belum
     * punya versi apa pun — JANGAN memblokir teknisi (dipakai jalur tutup WO).
     */
    public function activeOrSeedDefault(int $tenantId): InstallationTerms
    {
        return $this->active($tenantId)
            ?? $this->saveNewVersion($tenantId, self::DEFAULT_V1_BODY, null);
    }

    /**
     * @return Collection<int, InstallationTerms>
     */
    public function history(int $tenantId)
    {
        return InstallationTerms::query()
            ->where('tenant_id', $tenantId)
            ->with('createdBy')
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Simpan body sebagai versi BARU (version tertinggi + 1). "Pakai ulang
     * versi lama" juga lewat sini — ia tetap angka versi baru, bukan menimpa.
     * Dibungkus transaksi + lockForUpdate supaya 2 simpan bersamaan tidak
     * menghasilkan angka versi kembar (ditolak unique index juga, ini
     * mencegahnya lebih dulu).
     */
    public function saveNewVersion(int $tenantId, string $body, ?int $createdBy): InstallationTerms
    {
        return DB::transaction(function () use ($tenantId, $body, $createdBy) {
            $maxVersion = (int) InstallationTerms::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->max('version');

            return InstallationTerms::query()->create([
                'tenant_id' => $tenantId,
                'version' => $maxVersion + 1,
                'body' => $body,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }
}
