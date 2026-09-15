<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
     * @param  array{name: string, email: string, role: string, tenant_id: int}  $data
     * @return array{user: User, generated_password: string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $generatedPassword = Str::password(16);

            $user = User::create([
                'tenant_id' => $data['tenant_id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($generatedPassword),
                'email_verified_at' => now(),
            ]);

            $user->assignRole($data['role']);

            return ['user' => $user->fresh(), 'generated_password' => $generatedPassword];
        });
    }

    /**
     * Nama/email/role saja — TANPA password (password auto-sent/regenerate
     * adalah scope v0.22.3, bukan di sini). `syncRoles()` dipakai (bukan
     * `assignRole()`) supaya role lama benar-benar lepas — satu staff cuma
     * boleh punya satu role di CRUD ini (single-choice, dikunci eksplisit).
     *
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
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
}
