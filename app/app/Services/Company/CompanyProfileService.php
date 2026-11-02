<?php

namespace App\Services\Company;

use App\Models\CompanyProfile;
use App\Models\Reseller;
use App\Models\Tenant;

/**
 * v0.19.1 — satu sumber kebenaran identitas perusahaan per tenant, dengan
 * fallback yang jelas supaya konsumen (cetak invoice, persyaratan WO nanti,
 * variabel WA) tidak pernah menampilkan nilai kosong mentah.
 *
 * Rantai fallback (dari paling spesifik):
 *   legal_name  : profile → config('invoice.company_name') → tenant->name
 *   brand_name  : profile → legal_name (yang sudah ber-fallback)
 *   alamat      : profile → config('invoice.company_address') → ''
 *   email       : profile → config('invoice.company_email') → ''
 *   nomor CS    : override reseller → profile → config('invoice.company_phone')
 *                 → '[kontak belum diatur]'
 *
 * Env INVOICE_COMPANY_* (config/invoice.php) sengaja TETAP sebagai fallback
 * TERAKHIR sebelum teks '[kontak belum diatur]' — jangan dihapus.
 */
class CompanyProfileService
{
    public const CS_UNSET_PLACEHOLDER = '[kontak belum diatur]';

    public const DEFAULT_OPERATING_HOURS = '24 Jam';

    /**
     * Baris profil per tenant (dibuat minimal kalau belum ada) — dipakai
     * halaman pengaturan untuk diedit. firstOrCreate di sini aman karena
     * semua kolom nullable.
     */
    public function forTenant(int $tenantId): CompanyProfile
    {
        return CompanyProfile::query()->firstOrCreate(['tenant_id' => $tenantId]);
    }

    /**
     * Nilai efektif (sudah ber-fallback) untuk dikonsumsi. `$resellerId`
     * hanya memengaruhi nomor CS (satu-satunya yang boleh di-override
     * reseller).
     *
     * @return array<string, string>
     */
    public function resolveFor(int $tenantId, ?int $resellerId = null): array
    {
        $profile = CompanyProfile::query()->where('tenant_id', $tenantId)->first();
        $tenantName = Tenant::query()->whereKey($tenantId)->value('name') ?? '';

        $legalName = $this->firstNonEmpty([
            $profile?->legal_name,
            config('invoice.company_name'),
            $tenantName,
        ], '');

        $brandName = $this->firstNonEmpty([$profile?->brand_name, $legalName], '');

        $address = $this->firstNonEmpty([
            $profile?->address,
            config('invoice.company_address'),
        ], '');

        $email = $this->firstNonEmpty([
            $profile?->email,
            config('invoice.company_email'),
        ], '');

        return [
            'legal_name' => $legalName,
            'brand_name' => $brandName,
            'address' => $address,
            'city' => (string) ($profile?->city ?? ''),
            'postal_code' => (string) ($profile?->postal_code ?? ''),
            'npwp' => (string) ($profile?->npwp ?? ''),
            'email' => $email,
            'operating_hours' => $this->firstNonEmpty([$profile?->operating_hours], self::DEFAULT_OPERATING_HOURS),
            'bank_name' => (string) ($profile?->bank_name ?? ''),
            'bank_account_number' => (string) ($profile?->bank_account_number ?? ''),
            'bank_account_holder' => (string) ($profile?->bank_account_holder ?? ''),
            'cs' => $this->resolveCs($profile, $tenantId, $resellerId),
        ];
    }

    /**
     * Nomor CS efektif: override reseller → CS tenant → env → placeholder.
     */
    public function resolveCs(?CompanyProfile $profile, int $tenantId, ?int $resellerId): string
    {
        $profile ??= CompanyProfile::query()->where('tenant_id', $tenantId)->first();

        $resellerOverride = null;
        if ($resellerId !== null) {
            $reseller = Reseller::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($resellerId)
                ->first();

            $resellerOverride = $this->firstNonEmpty([
                $reseller?->cs_whatsapp,
                $reseller?->cs_phone,
            ], null);
        }

        return $this->firstNonEmpty([
            $resellerOverride,
            $profile?->cs_whatsapp,
            $profile?->cs_phone,
            config('invoice.company_phone'),
        ], self::CS_UNSET_PLACEHOLDER);
    }

    /**
     * Field kritis yang belum diisi — untuk banner peringatan di halaman.
     * Mengembalikan label-label Indonesia yang perlu diisi admin.
     *
     * @return list<string>
     */
    public function missing(int $tenantId): array
    {
        $profile = CompanyProfile::query()->where('tenant_id', $tenantId)->first();
        $missing = [];

        // legal_name dianggap "ada" kalau profil mengisinya ATAU env mengisi
        // — fallback ke nama tenant TIDAK dihitung sebagai "sudah diatur"
        // (nama tenant adalah slug internal, bukan identitas resmi).
        if (! $this->hasValue($profile?->legal_name) && ! $this->hasValue(config('invoice.company_name'))) {
            $missing[] = 'Nama perusahaan (legal)';
        }

        $csSet = $this->hasValue($profile?->cs_whatsapp)
            || $this->hasValue($profile?->cs_phone)
            || $this->hasValue(config('invoice.company_phone'));
        if (! $csSet) {
            $missing[] = 'Nomor Customer Service';
        }

        if (! $this->hasValue($profile?->address) && ! $this->hasValue(config('invoice.company_address'))) {
            $missing[] = 'Alamat perusahaan';
        }

        return $missing;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstNonEmpty(array $candidates, mixed $default): mixed
    {
        foreach ($candidates as $candidate) {
            if ($this->hasValue($candidate)) {
                return (string) $candidate;
            }
        }

        return $default;
    }
}
