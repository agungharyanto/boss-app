<?php

use App\Support\WhatsappPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIX v0.22.2 — bug login via HP untuk akun LAMA (mis. Agung Haryanto,
 * id=37, users.email agung.haryanto@bajastu.id).
 *
 * AKAR MASALAH (dikonfirmasi lewat investigasi read-only sebelum migration
 * ini ditulis): `StaffService::create()`/`update()` menormalisasi
 * `users.phone` via `WhatsappPhone::normalize()` SAAT create/update baru
 * dipanggil — tapi ini TIDAK RETROAKTIF. Baris `users.phone` yang sudah ada
 * SEBELUM normalize-on-save itu diterapkan (Agung sendiri, dibuat lama
 * sebelum revisi v0.22.2) tetap tersimpan MENTAH ('087884374939', bukan
 * '6287884374939'). Migration schema sebelumnya
 * (2026_09_15_140000_make_phone_required_and_email_optional_on_users_table)
 * hanya mem-backfill PLACEHOLDER ('no-phone-{id}') untuk baris yang
 * phone-nya KOSONG — baris yang SUDAH punya nomor sama sekali tidak
 * tersentuh, jadi bug ini genuinely tidak pernah ketutup migration
 * sebelumnya.
 *
 * `LoginIdentifierResolver::resolvePhoneUser()` menormalisasi INPUT via
 * `WhatsappPhone::normalize($phone)` lalu query `WHERE phone = $normalized`
 * — logic-nya SENDIRI sudah benar sesuai desain (lihat docblock method itu),
 * tapi karena kolom DB masih mentah untuk baris lama, query yang sudah
 * dinormalisasi TIDAK PERNAH match nilai mentah itu — apa pun format yang
 * diketik user saat login (0812.../+62812.../62812...) semuanya ter-
 * normalize jadi '6287884374939', sementara kolom tetap '087884374939'.
 * Login via HP untuk akun ini SELALU gagal, bukan gagal-di-format-tertentu.
 *
 * FIX: migration DATA murni (bukan schema) — menormalisasi ULANG setiap
 * baris `users.phone` yang GENUINELY berisi nomor telepon. Baris placeholder
 * ('no-phone-{id}', dari migration backfill sebelumnya) SENGAJA DI-SKIP —
 * kalau ikut dinormalisasi, regex `WhatsappPhone::normalize()` akan
 * mengambil digit yang kebetulan ada di placeholder itu sendiri (id-nya)
 * dan menghasilkan nomor telepon PALSU yang salah total (mis.
 * 'no-phone-28' -> '6228', BUKAN nomor telepon sungguhan siapa pun).
 *
 * Guard tabrakan (sesuai instruksi eksplisit — "kalau ada 2 baris yang
 * setelah dinormalisasi jadi sama persis, STOP dan laporkan dulu, jangan
 * dipaksa"): SEMUA baris dihitung dulu, kalau ada tabrakan (baik antar
 * baris yang sama-sama akan diupdate, maupun terhadap baris lain yang
 * kebetulan SUDAH punya nilai target itu) migration BERHENTI dengan
 * RuntimeException SEBELUM menulis satu UPDATE pun — tidak pernah memaksa
 * salah satu menang begitu saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->select('id', 'phone')->get();

        $updates = [];

        foreach ($rows as $row) {
            if ($row->phone === null || $row->phone === '' || str_starts_with($row->phone, 'no-phone-')) {
                continue;
            }

            $normalized = WhatsappPhone::normalize($row->phone);

            // Tidak ada digit sama sekali (seharusnya tidak pernah terjadi
            // untuk baris yang lolos guard placeholder di atas) — biarkan
            // apa adanya daripada menulis string kosong ke kolom NOT NULL.
            if ($normalized === '' || $normalized === $row->phone) {
                continue;
            }

            $updates[] = ['id' => $row->id, 'from' => $row->phone, 'to' => $normalized];
        }

        if ($updates === []) {
            return;
        }

        // Cek tabrakan SEBELUM menulis apa pun. Target (nilai setelah
        // normalize) dibandingkan terhadap: (a) target baris lain yang
        // JUGA akan diupdate, (b) phone baris LAIN yang tidak ikut
        // diupdate (mis. sudah ter-normalize duluan atau placeholder).
        $updatingIds = array_column($updates, 'id');
        $othersPhoneById = DB::table('users')
            ->whereNotIn('id', $updatingIds)
            ->pluck('phone', 'id');

        $targetToIds = [];
        foreach ($updates as $u) {
            $targetToIds[$u['to']][] = $u['id'];
        }
        foreach ($othersPhoneById as $id => $phone) {
            if ($phone !== null && isset($targetToIds[$phone])) {
                $targetToIds[$phone][] = $id;
            }
        }

        $collisions = array_filter($targetToIds, fn (array $ids) => count($ids) > 1);

        if ($collisions !== []) {
            $detail = collect($collisions)
                ->map(fn (array $ids, string $phone) => "{$phone} <- users.id ".implode(',', $ids))
                ->implode('; ');

            throw new RuntimeException(
                'Migration normalisasi users.phone DIBATALKAN — ada tabrakan setelah '.
                "normalisasi (tidak ada baris yang diubah): {$detail}. Tentukan manual nomor ".
                'mana yang benar untuk masing-masing baris sebelum migrate ulang.'
            );
        }

        foreach ($updates as $u) {
            DB::table('users')->where('id', $u['id'])->update(['phone' => $u['to']]);
        }
    }

    public function down(): void
    {
        // Migration data murni — bentuk mentah aslinya tidak disimpan di
        // mana pun (tidak ada log/kolom cadangan), jadi tidak ada cara
        // aman membalikkan normalisasi. Sengaja no-op, bukan destruktif.
    }
};
