<?php

namespace Database\Seeders;

use App\Models\InstallationTerms;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * v0.19.1 — seed Syarat & Ketentuan Pemasangan VERSI 1 (DRAFT) per tenant.
 * Idempoten (firstOrCreate pada tenant_id+version=1) — aman di-run ulang,
 * tidak pernah menimpa versi yang sudah diedit admin lewat editor.
 *
 * Teks ini DRAFT — Agung mengubahnya lewat editor "Syarat & Ketentuan
 * Pemasangan" (tiap simpan = versi baru). Hanya memakai variabel whitelist
 * TermsRenderer::WHITELIST. {tanggal_jatuh_tempo} SENGAJA TIDAK dipakai
 * (tidak ada sumber andal saat render) — pasal 4 memakai kalimat
 * "sesuai tanggal jatuh tempo pada tagihan".
 */
class InstallationTermsSeeder extends Seeder
{
    public function run(): void
    {
        // DRAFT v1 — 10 pasal, variabel whitelist terisi.
        $body = <<<'TERMS'
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

        Tenant::all()->each(function (Tenant $tenant) use ($body) {
            InstallationTerms::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'version' => 1,
                ],
                [
                    'body' => $body,
                    'created_by' => null,
                    'created_at' => now(),
                ]
            );
        });
    }
}
