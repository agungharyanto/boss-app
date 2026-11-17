<?php

namespace App\Services\Company;

use App\Models\CompanyBankAccount;
use App\Models\CompanyBankAccountLog;
use App\Models\CompanyProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * v0.13.5.4 — logika rekening tujuan transfer manual (BUKAN di Livewire).
 *
 * Aturan:
 * - Rekening pertama otomatis jadi default + aktif.
 * - Default tidak boleh dihapus / dinonaktifkan selama masih ada rekening lain
 *   (pilih default baru dulu). Bila hanya tersisa satu, boleh.
 * - Tiap perubahan dicatat APPEND-ONLY ke company_bank_account_logs (nomor
 *   ter-mask). created_by/updated_by terisi.
 * - Validasi: bank_name & account_holder wajib; account_number digit/spasi/hubung.
 *
 * Semua query memfilter tenant_id eksplisit (model tanpa BelongsToTenant).
 */
class CompanyBankAccountService
{
    /** @return Collection<int, CompanyBankAccount> */
    public function allAccounts(int $tenantId): Collection
    {
        return CompanyBankAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, CompanyBankAccount> Default paling atas. */
    public function activeAccounts(int $tenantId): Collection
    {
        return CompanyBankAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function defaultAccount(int $tenantId): ?CompanyBankAccount
    {
        return CompanyBankAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function create(int $tenantId, array $data, ?int $actingUserId = null): CompanyBankAccount
    {
        $data = $this->validate($data);

        return DB::transaction(function () use ($tenantId, $data, $actingUserId) {
            $isFirst = CompanyBankAccount::query()->where('tenant_id', $tenantId)->count() === 0;
            $maxSort = (int) CompanyBankAccount::query()->where('tenant_id', $tenantId)->max('sort_order');

            $account = CompanyBankAccount::create([
                'tenant_id' => $tenantId,
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_holder' => $data['account_holder'],
                'sort_order' => $maxSort + 1,
                'is_default' => $isFirst,
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            $this->log($account, 'created', null, $this->snapshot($account), $actingUserId);

            return $account;
        });
    }

    public function update(CompanyBankAccount $account, array $data, ?int $actingUserId = null): CompanyBankAccount
    {
        $data = $this->validate($data);
        $before = $this->snapshot($account);

        $account->update([
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_holder' => $data['account_holder'],
            'updated_by' => $actingUserId,
        ]);

        $this->log($account, 'updated', $before, $this->snapshot($account->fresh()), $actingUserId);

        return $account->fresh();
    }

    public function delete(CompanyBankAccount $account, ?int $actingUserId = null): void
    {
        if ($account->is_default && $this->otherCount($account) > 0) {
            throw new RuntimeException('Rekening default tidak bisa dihapus selama masih ada rekening lain. Jadikan rekening lain sebagai default dulu.');
        }

        $before = $this->snapshot($account);
        $this->log($account, 'deleted', $before, null, $actingUserId);
        $account->delete();
    }

    public function makeDefault(CompanyBankAccount $account, ?int $actingUserId = null): void
    {
        if (! $account->is_active) {
            throw new RuntimeException('Rekening yang nonaktif tidak bisa dijadikan default. Aktifkan dulu.');
        }

        DB::transaction(function () use ($account, $actingUserId) {
            CompanyBankAccount::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('is_default', true)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false, 'updated_by' => $actingUserId]);

            $account->update(['is_default' => true, 'updated_by' => $actingUserId]);
            $this->log($account, 'made_default', null, $this->snapshot($account->fresh()), $actingUserId);
        });
    }

    public function setActive(CompanyBankAccount $account, bool $active, ?int $actingUserId = null): void
    {
        if (! $active && $account->is_default && $this->otherCount($account) > 0) {
            throw new RuntimeException('Rekening default tidak bisa dinonaktifkan selama masih ada rekening lain. Jadikan rekening lain sebagai default dulu.');
        }

        $before = $this->snapshot($account);
        $account->update(['is_active' => $active, 'updated_by' => $actingUserId]);
        $this->log($account, $active ? 'activated' : 'deactivated', $before, $this->snapshot($account->fresh()), $actingUserId);
    }

    /**
     * Migrasi data: buat 1 rekening default aktif dari kolom lama
     * company_profiles.bank_* bila tenant belum punya rekening. Idempoten.
     */
    public function backfillFromProfile(int $tenantId): ?CompanyBankAccount
    {
        if (CompanyBankAccount::query()->where('tenant_id', $tenantId)->exists()) {
            return null;
        }

        $profile = CompanyProfile::query()->where('tenant_id', $tenantId)->first();
        $bankName = trim((string) ($profile?->bank_name ?? ''));
        if ($bankName === '') {
            return null;
        }

        return CompanyBankAccount::create([
            'tenant_id' => $tenantId,
            'bank_name' => $bankName,
            'account_number' => (string) ($profile->bank_account_number ?? ''),
            'account_holder' => (string) ($profile->bank_account_holder ?? ''),
            'sort_order' => 0,
            'is_default' => true,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
        ]);
    }

    public function logsFor(int $tenantId, int $limit = 50): Collection
    {
        return CompanyBankAccountLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function otherCount(CompanyBankAccount $account): int
    {
        return CompanyBankAccount::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('id', '!=', $account->id)
            ->count();
    }

    /** @return array{bank_name:string,account_number:string,account_holder:string} */
    private function validate(array $data): array
    {
        $validated = Validator::make($data, [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_holder' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9\s\-]+$/'],
        ], [
            'bank_name.required' => 'Nama bank wajib diisi.',
            'account_holder.required' => 'Atas nama wajib diisi.',
            'account_number.required' => 'Nomor rekening wajib diisi.',
            'account_number.regex' => 'Nomor rekening hanya boleh angka, spasi, atau tanda hubung.',
        ])->validate();

        $validated['bank_name'] = trim($validated['bank_name']);
        $validated['account_holder'] = trim($validated['account_holder']);
        $validated['account_number'] = trim($validated['account_number']);

        return $validated;
    }

    private function snapshot(CompanyBankAccount $account): array
    {
        return [
            'bank_name' => $account->bank_name,
            'account_number' => CompanyBankAccount::maskNumber($account->account_number),
            'account_holder' => $account->account_holder,
            'is_default' => $account->is_default,
            'is_active' => $account->is_active,
        ];
    }

    private function log(CompanyBankAccount $account, string $action, ?array $before, ?array $after, ?int $actingUserId): void
    {
        CompanyBankAccountLog::create([
            'tenant_id' => $account->tenant_id,
            'bank_account_id' => $account->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => $actingUserId,
        ]);
    }
}
