<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\CpeActionLog;
use App\Models\ResellerUser;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WhatsappPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v0.22.1 — CRUD Staff (Manajemen User). Mirror pola ReferrerService untuk
 * generate password (Str::password(16) + Hash::make, ditampilkan SATU KALI
 * lewat return value, tidak pernah disimpan/logged) — bedanya di sini akun
 * staff MEMANG diberi satu role Spatie (termasuk superadmin — sudah dikunci
 * eksplisit: "semua 9 role selectable termasuk superadmin, single-choice"),
 * bukan zero-role seperti akun Referrer.
 *
 * Semua method di sini eksplisit tenant-scoped lewat parameter — `User`
 * model TIDAK pakai `BelongsToTenant` (lihat CLAUDE.md), jadi `tenant_id`
 * TIDAK pernah auto-fill dan query terhadap `User` TIDAK pernah otomatis
 * ter-scope. Caller (Livewire component) bertanggung jawab meneruskan
 * `tenant_id` yang benar (auth()->user()->tenant_id).
 */
class StaffService
{
    /**
     * v0.22.2 — `phone` sekarang WAJIB (alat login utama), `email` jadi
     * opsional. `WhatsappPhone::normalize()` dipanggil di sini SEBAGAI
     * DEFENSE-IN-DEPTH (caller Livewire sudah menormalisasi sebelum
     * `Rule::unique()`-nya sendiri dijalankan, supaya deteksi duplikat
     * apple-to-apple — lihat `StaffIndex::createStaff()`) — memanggilnya
     * lagi di sini idempoten (nomor yang sudah `62xxx` tidak berubah),
     * jadi aman dipanggil dua kali, dan `StaffService` tetap benar kalau
     * suatu saat dipanggil langsung tanpa lewat Livewire.
     *
     * @param  array{name: string, email?: ?string, phone: string, role: string, tenant_id: int}  $data
     * @return array{user: User, generated_password: string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $generatedPassword = Str::password(16);
            $email = $data['email'] ?? null;

            $user = User::create([
                'tenant_id' => $data['tenant_id'],
                'name' => $data['name'],
                'email' => $email,
                'phone' => WhatsappPhone::normalize($data['phone']),
                'password' => Hash::make($generatedPassword),
                // Cuma masuk akal diberi tanggal verifikasi kalau memang
                // punya email untuk diverifikasi.
                'email_verified_at' => $email !== null ? now() : null,
            ]);

            $user->assignRole($data['role']);

            return ['user' => $user->fresh(), 'generated_password' => $generatedPassword];
        });
    }

    /**
     * Nama/email/HP/role saja — TANPA password (password auto-sent/regenerate
     * adalah scope v0.22.4, bukan di sini). `syncRoles()` dipakai (bukan
     * `assignRole()`) supaya role lama benar-benar lepas — satu staff cuma
     * boleh punya satu role di CRUD ini (single-choice, dikunci eksplisit).
     *
     * @param  array{name: string, email?: ?string, phone: string, role: string}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => WhatsappPhone::normalize($data['phone']),
        ]);

        $user->syncRoles([$data['role']]);

        return $user->fresh();
    }

    /**
     * Soft-disable — bukan soft-delete Eloquent, murni flag boolean yang
     * dicek di titik login (lihat FortifyServiceProvider::authenticateUsing()
     * dan ReferrerLoginController::login()). UI harus pakai teks
     * "Disable"/"Enable", BUKAN "Aktifkan"/"Nonaktifkan" — dikunci eksplisit.
     */
    public function disable(User $user): User
    {
        $user->update(['is_disabled' => true]);

        return $user->fresh();
    }

    public function enable(User $user): User
    {
        $user->update(['is_disabled' => false]);

        return $user->fresh();
    }

    /**
     * Hard delete beneran — `users` TIDAK pakai SoftDeletes (dikonfirmasi
     * langsung dari migration+model sebelum ditulis, bukan diasumsikan).
     *
     * WAJIB dicek dulu SEBELUM `$staff->delete()` — 3 relasi nyata yang
     * DB sendiri TIDAK akan menahan dengan pesan jelas (lihat investigasi
     * Langkah 0 v0.22.1 di CLAUDE.md untuk daftar FK lengkap):
     *  - `reseller_users.user_id` CASCADE — diam-diam menghapus baris
     *    keanggotaan reseller kalau tidak di-guard. Diblokir kalau ADA
     *    baris APA PUN (status apa pun), dikunci eksplisit — bukan cuma
     *    yang status=active.
     *  - `technicians.user_id` CASCADE — diam-diam menghapus baris
     *    Technician + histori klaim WO (`work_order_technicians`, juga
     *    cascade dari `technicians`). Diblokir kalau baris Technician ADA
     *    SAMA SEKALI, apa pun status Work Order-nya — dikunci eksplisit,
     *    bukan cuma yang masih punya WO aktif. Pesan dibedakan: kalau
     *    genuinely ada WO belum selesai, sebutkan jumlahnya; kalau tidak,
     *    tetap diblokir tapi dengan alasan generik "masih terdaftar
     *    sebagai akun Teknisi".
     *  - `cpe_action_logs.performed_by` — `constrained('users')` TANPA
     *    `nullOnDelete()` sama sekali di migration aslinya = default
     *    RESTRICT di level DB. Tanpa guard ini, `$staff->delete()` akan
     *    throw QueryException MENTAH (FK violation), bukan pesan yang
     *    bisa dibaca admin.
     *
     * Setiap relasi lain yang menunjuk ke `users` sudah `nullOnDelete()`
     * (histori tetap utuh, aktor jadi null) — TIDAK memblokir delete.
     *
     * @throws RuntimeException kalau masih ada relasi yang nyantol, pesan
     *                          spesifik per kasus (bukan generik).
     */
    public function delete(User $staff): void
    {
        $resellerMemberships = ResellerUser::where('user_id', $staff->id)
            ->with('reseller')
            ->get();

        if ($resellerMemberships->isNotEmpty()) {
            $names = $resellerMemberships->pluck('reseller.name')->filter()->unique()->implode(', ');

            throw new RuntimeException("Tidak bisa dihapus — staff ini masih terdaftar sebagai member reseller: {$names}.");
        }

        $technician = Technician::withoutGlobalScopes()->where('user_id', $staff->id)->first();

        if ($technician !== null) {
            $activeWorkOrderCount = WorkOrder::withoutGlobalScopes()
                ->where('technician_id', $technician->id)
                ->whereNotIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value])
                ->count();

            if ($activeWorkOrderCount > 0) {
                throw new RuntimeException("Tidak bisa dihapus — staff ini masih jadi teknisi penanggung jawab {$activeWorkOrderCount} Work Order aktif.");
            }

            throw new RuntimeException('Tidak bisa dihapus — staff ini masih terdaftar sebagai akun Teknisi. Lepas/hapus status teknisi ini dulu sebelum menghapus akun staff.');
        }

        $actionLogCount = CpeActionLog::withoutGlobalScopes()->where('performed_by', $staff->id)->count();

        if ($actionLogCount > 0) {
            throw new RuntimeException("Tidak bisa dihapus — staff ini tercatat pernah melakukan {$actionLogCount} aksi perangkat CPE (riwayat audit tidak boleh kehilangan aktornya).");
        }

        $staff->delete();
    }
}
