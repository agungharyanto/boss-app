<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Revisi Pesan Error Bahasa Indonesia — satu sumber tunggal untuk nama field
 * (bahasa Indonesia) dipakai lintas seluruh cluster "Profil Paket"
 * (Bandwidth Profile v0.14.1, IP Pool Pelanggan v0.14.2, Grup Profil
 * v0.14.3, Profil Hotspot v0.14.4, Profil PPP v0.14.5) — supaya pesan
 * validasi seperti "Harga Jual wajib diisi." bukan "The sell price field is
 * required." (`lang/id/validation.php` sendiri, sejak revisi ini, cuma
 * menerjemahkan KATA KERJA/STRUKTUR pesannya, bukan nama field domain kita —
 * itu tugas array `attributes` per-request/komponen).
 *
 * Dipakai dari 2 sisi berbeda tanpa duplikasi:
 * - `forFormRequest()` — dipanggil dari `attributes()` di setiap
 *   Store/UpdateXRequest (snake_case, sesuai nama kolom DB / key rules()).
 * - `forLivewire()` — dipanggil dari `validationAttributes()` di setiap
 *   Livewire index component (camelCase, sesuai nama property Livewire) —
 *   OTOMATIS men-generate juga varian `edit`-prefixed (mis. `sellPrice` DAN
 *   `editSellPrice` sama-sama "Harga Jual") dari SATU label snake_case yang
 *   sama, supaya tidak perlu menulis ulang tiap label 2x per komponen.
 *
 * Daftar di bawah sengaja mencakup field dari SEMUA 5 modul sekaligus
 * (bukan di-filter per-request) — Laravel/Livewire cuma memakai key yang
 * benar-benar direferensikan oleh rules() komponen itu sendiri, jadi entry
 * yang tidak relevan untuk suatu form aman diabaikan, bukan disalahgunakan.
 */
class ProfilPaketAttributeLabels
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        // Umum (dipakai lebih dari satu modul)
        'name' => 'Nama',
        'is_active' => 'Status Aktif',

        // Bandwidth Profile (v0.14.1)
        'upload_min' => 'Upload Minimum',
        'upload_max' => 'Upload Maksimum',
        'download_min' => 'Download Minimum',
        'download_max' => 'Download Maksimum',
        'unit' => 'Satuan',

        // IP Pool Pelanggan (v0.14.2)
        'nas_id' => 'NAS',
        'usage_type' => 'Tipe Pemakaian',
        'network_address' => 'Network Address',
        'gateway_ip' => 'Gateway IP',
        'range_start' => 'Range Start',
        'range_end' => 'Range End',
        'dns_primary' => 'DNS Primer',
        'dns_secondary' => 'DNS Sekunder',

        // Grup Profil (v0.14.3, + revisi Interface/VLAN & PPPoE Server)
        'customer_ip_pool_id' => 'IP Pool',
        'type' => 'Tipe',
        'parent_queue' => 'Parent Queue',
        'interface_name' => 'Interface/VLAN',
        'service_name' => 'Service Name',

        // Profil Hotspot (v0.14.4) & Profil PPP (v0.14.5) — field bersama
        'network_profile_group_id' => 'Grup Profil',
        'bandwidth_profile_id' => 'Bandwidth Profile',
        'cost_price' => 'Harga Modal',
        'sell_price' => 'Harga Jual',
        'promo_price' => 'Harga Promo',
        'tax_percent' => 'PPN',
        'shared_users' => 'Shared Users',
        'priority' => 'Prioritas',
        'login_days' => 'Periode Login - Hari',
        'login_start_time' => 'Jam Mulai Login',
        'login_end_time' => 'Jam Selesai Login',
        'visible_to_reseller' => 'Dapat Diakses Operator/Reseller',
        'active_duration_value' => 'Nilai Masa Aktif',
        'active_duration_unit' => 'Satuan Masa Aktif',

        // Profil Hotspot saja (v0.14.4)
        'show_in_voucher_form' => 'Tampilkan di Form Beli e-Voucher',
        'profile_type' => 'Tipe Profil',
        'limit_type' => 'Batasan',
        'quota_value' => 'Kuota',
        'quota_unit' => 'Satuan Data',
    ];

    /**
     * @return array<string, string>
     */
    public static function forFormRequest(): array
    {
        return self::LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function forLivewire(): array
    {
        $map = [];

        foreach (self::LABELS as $snake => $label) {
            $camel = Str::camel($snake);
            $map[$camel] = $label;
            $map['edit'.ucfirst($camel)] = $label;
        }

        return $map;
    }
}
