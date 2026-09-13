<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\DB;

/**
 * v0.12.2 (migrasi PPPoE VLAN10 -> radcheck, Track A) — penulis PERTAMA ke
 * `radius_db.radcheck` di codebase ini. Sebelum ini tidak ada satu pun kode
 * aplikasi yang menulis `radcheck` — hanya `radreply` per-user (lihat
 * RadiusSessionHistoryService/NetworkProfileGroupService::writeRadiusGroupReply()
 * untuk `radgroupreply`, konsep berbeda).
 *
 * Pola sama seperti `NetworkProfileGroupService::writeRadiusGroupReply()`:
 * `DB::connection('radius')`, kolom lowercase (`username`/`attribute`/`op`/
 * `value` — PostgreSQL melipat identifier tanpa quote ke huruf kecil,
 * `schema.sql` sumbernya sendiri ditulis mixed-case tapi TIDAK
 * merepresentasikan skema real). Tidak ada UNIQUE constraint di `radcheck`/
 * `radreply` (cuma index non-unik `(username, attribute)`) — idempoten
 * dicapai app-level lewat delete-then-insert per username ("rewrite
 * wholesale"), BUKAN upsert SQL asli.
 *
 * Convention `Cleartext-Password := <username>` (password == username)
 * dipertahankan identik dengan ~99.6% baris existing di tabel ini.
 */
class RadcheckWriterService
{
    /**
     * Tulis (atau tulis ulang) kredensial + reply attribute PPP standar
     * untuk satu username. Aman dijalankan berkali-kali untuk username yang
     * sama — baris lama untuk username itu dihapus dulu dari KEDUA tabel
     * sebelum baris baru ditulis.
     *
     * @param  string  $framedPool  Nilai `Framed-Pool` (tier 1/2/3, lihat
     *                              laporan dry-run v2 3-tier).
     */
    public function write(string $username, string $password, string $framedPool): void
    {
        DB::connection('radius')->table('radcheck')->where('username', $username)->delete();
        DB::connection('radius')->table('radreply')->where('username', $username)->delete();

        DB::connection('radius')->table('radcheck')->insert([
            'username' => $username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => $password,
        ]);

        DB::connection('radius')->table('radreply')->insert([
            ['username' => $username, 'attribute' => 'Service-Type', 'op' => '=', 'value' => 'Framed-User'],
            ['username' => $username, 'attribute' => 'Framed-Protocol', 'op' => '=', 'value' => 'PPP'],
            ['username' => $username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => $framedPool],
        ]);
    }

    /**
     * Hapus total kredensial + reply attribute satu username dari kedua
     * tabel — dipakai kalau sebuah baris hasil migrasi ternyata perlu
     * di-rollback per-username tanpa menyentuh baris lain.
     */
    public function remove(string $username): void
    {
        DB::connection('radius')->table('radcheck')->where('username', $username)->delete();
        DB::connection('radius')->table('radreply')->where('username', $username)->delete();
    }
}
