<?php

namespace App\Services;

use App\Models\Referrer;
use App\Models\User;
use App\Support\WhatsappPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReferrerService
{
    /**
     * @param  array{name: string, phone: string, type: string, is_active?: bool}  $data
     * @return array{referrer: Referrer, generated_password: ?string}
     */
    public function create(array $data, bool $createLoginAccount): array
    {
        return DB::transaction(function () use ($data, $createLoginAccount) {
            $referrer = Referrer::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'type' => $data['type'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $generatedPassword = null;

            if ($createLoginAccount) {
                $generatedPassword = $this->attachNewLoginAccount($referrer);
            }

            return ['referrer' => $referrer, 'generated_password' => $generatedPassword];
        });
    }

    /**
     * @param  array{name?: string, phone?: string, type?: string, is_active?: bool}  $data
     */
    public function update(Referrer $referrer, array $data): Referrer
    {
        $referrer->update($data);

        return $referrer->fresh();
    }

    /**
     * "Deactivate", not delete — a Referrer with existing referrals/
     * commission history must never be hard-deleted (no destroy() action
     * exists in this module at all, on purpose).
     */
    public function deactivate(Referrer $referrer): Referrer
    {
        $referrer->update(['is_active' => false]);

        return $referrer->fresh();
    }

    /**
     * Generates a brand-new User + random password for a Referrer that
     * currently has none (referrer.user_id is null) — the "generate akun
     * baru kapan saja lewat aksi terpisah di halaman edit" path.
     *
     * @return array{referrer: Referrer, generated_password: string}
     */
    public function generateLoginAccount(Referrer $referrer): array
    {
        if ($referrer->user_id !== null) {
            throw new InvalidArgumentException('Referrer ini sudah punya akun login.');
        }

        $generatedPassword = DB::transaction(fn () => $this->attachNewLoginAccount($referrer));

        return ['referrer' => $referrer->fresh(), 'generated_password' => $generatedPassword];
    }

    /**
     * Links an EXISTING User (not linked to any other Referrer — enforced
     * both here and by referrers.user_id's own DB-level unique constraint)
     * as this Referrer's login account, instead of generating a fresh one.
     * Never touches the User's own password.
     */
    public function linkExistingUser(Referrer $referrer, User $user): Referrer
    {
        if ($referrer->user_id !== null) {
            throw new InvalidArgumentException('Referrer ini sudah punya akun login.');
        }

        if (Referrer::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
            throw new InvalidArgumentException('User ini sudah terhubung ke Referrer lain.');
        }

        $referrer->update(['user_id' => $user->id]);

        return $referrer->fresh();
    }

    /**
     * Never persisted anywhere beyond this one in-memory return value — the
     * caller (ReferrerController/the Livewire admin form) is responsible for
     * showing it exactly once and never logging/storing it. The generated
     * User gets NO Spatie role at all (a fresh User has none by default) —
     * deliberately never Administrator/superadmin, so this account can only
     * ever reach the Referrer portal (see EnsureReferrerPortalAccess), never
     * the admin panel.
     *
     * users.email has no real use for a Referrer account (login is phone +
     * password, see the referrer portal login flow) but the column is
     * globally unique at the schema level — a deterministic placeholder
     * keyed off the Referrer's own id (unique by construction) is
     * synthesized here rather than asking the admin to type one in.
     *
     * v0.22.2 — users.phone is now NOT NULL + UNIQUE globally (real
     * consequence of that migration, found and fixed while building it:
     * this was the ONE other genuinely active feature besides StaffService
     * that calls User::create() — it would have started throwing a raw
     * QueryException on every single call otherwise). Sourced from the
     * Referrer's own phone (normalized) — referrers.phone is only unique
     * PER TENANT, so a cross-tenant collision is a real, if rare,
     * possibility; checked explicitly here so it surfaces as a clear
     * message instead of a raw DB constraint violation.
     */
    private function attachNewLoginAccount(Referrer $referrer): string
    {
        $generatedPassword = Str::password(16);
        $normalizedPhone = WhatsappPhone::normalize($referrer->phone);

        if (User::where('phone', $normalizedPhone)->exists()) {
            throw new InvalidArgumentException('Nomor HP Referrer ini sudah dipakai akun login lain (staff atau Referrer lain) — tidak bisa membuat akun login baru dengan nomor yang sama.');
        }

        $user = User::create([
            'tenant_id' => $referrer->tenant_id,
            'name' => $referrer->name,
            'email' => "referrer-{$referrer->id}@portal.local",
            'phone' => $normalizedPhone,
            'password' => Hash::make($generatedPassword),
            'email_verified_at' => now(),
        ]);

        $referrer->update(['user_id' => $user->id]);

        return $generatedPassword;
    }
}
