<?php

namespace App\Services\Company;

/**
 * v0.19.1 — merender teks Syarat & Ketentuan dengan variabel whitelist.
 *
 * Variabel v1 (dikunci — tambah variabel baru = keputusan tersendiri):
 *   {nama_perusahaan} {alamat_perusahaan} {nomor_cs}
 *   {nama_pelanggan} {alamat_pelanggan}
 *   {nama_paket} {biaya_bulanan} {tanggal}
 *
 * {tanggal_jatuh_tempo} SENGAJA TIDAK termasuk v1 — tidak ada sumber yang
 * andal saat render/tanda tangan (subscriptions nyaris dorman,
 * billing_cycle_day tidak dipakai; due_date hanya ada per-invoice yang
 * belum tentu terbit saat tutup WO). Pasal 4 pakai kalimat "sesuai tanggal
 * jatuh tempo pada tagihan" — lihat seed v1. Kalau nanti ada sumber andal,
 * tambahkan ke WHITELIST sebagai perubahan tersendiri.
 *
 * {biaya_bulanan} diisi oleh pemanggil sebagai snapshot
 * ppp_packages.sell_price SAAT render (tanpa promo_price) — renderer ini
 * tidak tahu dari mana nilainya; ia hanya mensubstitusi context.
 */
class TermsRenderer
{
    /**
     * @var list<string>
     */
    public const WHITELIST = [
        'nama_perusahaan',
        'alamat_perusahaan',
        'nomor_cs',
        'nama_pelanggan',
        'alamat_pelanggan',
        'nama_paket',
        'biaya_bulanan',
        'tanggal',
    ];

    /**
     * Substitusi {variabel}. Variabel whitelist yang tidak ada di context
     * dirender string kosong (bukan placeholder mentah). Variabel non-
     * whitelist DIBIARKAN mentah di sini — penolakannya dilakukan saat
     * SIMPAN (lihat unknownVariables()), bukan saat render, supaya body yang
     * sudah tersimpan tidak pernah mengandung variabel tak dikenal.
     *
     * @param  array<string, mixed>  $context
     */
    public function render(string $body, array $context): string
    {
        $replacements = [];

        foreach (self::WHITELIST as $key) {
            $value = $context[$key] ?? '';
            $replacements['{'.$key.'}'] = $value === null ? '' : (string) $value;
        }

        return strtr($body, $replacements);
    }

    /**
     * Daftar nama variabel {word} di body yang BUKAN bagian whitelist —
     * dipakai validasi saat simpan untuk menolak variabel tak dikenal.
     *
     * @return list<string>
     */
    public function unknownVariables(string $body): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $body, $matches);

        $found = array_unique($matches[1]);

        return array_values(array_filter(
            $found,
            fn (string $name) => ! in_array($name, self::WHITELIST, true),
        ));
    }

    /**
     * Context contoh untuk PRATINJAU di editor — nilai dummy yang jelas
     * terbaca sebagai contoh, bukan data nyata.
     *
     * @return array<string, string>
     */
    public function sampleContext(): array
    {
        return [
            'nama_perusahaan' => 'PT Contoh Jaringan Nusantara',
            'alamat_perusahaan' => 'Jl. Contoh No. 1, Kota Contoh',
            'nomor_cs' => '0812-3456-7890',
            'nama_pelanggan' => 'Budi Santoso',
            'alamat_pelanggan' => 'Jl. Melati No. 10, RT 02 RW 03',
            'nama_paket' => 'HomeFixed 10 Mbps',
            'biaya_bulanan' => '150.000',
            'tanggal' => now()->translatedFormat('d F Y'),
        ];
    }
}
