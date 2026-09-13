<?php

namespace App\Services\Network;

use App\Models\ModemType;
use App\Models\PppPackage;
use App\Models\WanConfigTemplate;
use InvalidArgumentException;

/**
 * v0.12.5 — CRUD `WanConfigTemplate` (matrix Paket x Tipe Modem). BOSS-006
 * — dipakai `App\Livewire\Network\WanConfigTemplateIndex`.
 *
 * TIDAK ADA dispatch job sync ke GenieACS di sini — GenieAcsPresetService
 * per-template (v0.12.5 lanjutan/v0.12.6, setelah UI ini direview) belum
 * dibangun. Baris tersimpan dengan `genieacs_sync_status` default
 * 'pending' begitu saja, tanpa job apa pun yang benar-benar memprosesnya.
 *
 * Setiap method di sini SENGAJA melakukan lookup ulang PppPackage/ModemType
 * via query SCOPED (bukan `withoutGlobalScopes()`/`withTrashed()`) —
 * SoftDeletingScope Eloquent otomatis mengecualikan baris yang sudah
 * soft-deleted, jadi ini SUDAH SETARA `whereNull('deleted_at')` eksplisit
 * tanpa perlu ditulis manual. Pelajaran langsung dari insiden verifikasi
 * v0.12.4 (PppPackage #17 "PPPoE-Remote" produksi ter-soft-delete tak
 * sengaja saat menguji restrictOnDelete()) — restrictOnDelete() pada FK
 * `wan_config_templates.ppp_package_id`/`.modem_type_id` HANYA memblokir
 * HARD delete, tidak soft-delete, jadi baris ini tidak boleh mengandalkan
 * FK constraint DB saja untuk menjamin referensinya masih hidup.
 */
class WanConfigTemplateService
{
    /**
     * @param  array{ppp_package_id: int, modem_type_id: ?int, enabled?: bool, wan1_enabled?: bool, wan1_vlan?: int, wan1_pppoe_username?: string, wan1_pppoe_password?: string, wan2_enabled?: bool, wan2_vlan?: int}  $data
     */
    public function create(array $data): WanConfigTemplate
    {
        $this->assertReferencesAlive($data['ppp_package_id'], $data['modem_type_id'] ?? null);
        $this->assertNoCollision($data['ppp_package_id'], $data['modem_type_id'] ?? null, null);

        return WanConfigTemplate::create($data);
    }

    /**
     * @param  array{ppp_package_id?: int, modem_type_id?: ?int, enabled?: bool, wan1_enabled?: bool, wan1_vlan?: int, wan1_pppoe_username?: string, wan1_pppoe_password?: string, wan2_enabled?: bool, wan2_vlan?: int}  $data
     */
    public function update(WanConfigTemplate $template, array $data): WanConfigTemplate
    {
        $pppPackageId = $data['ppp_package_id'] ?? $template->ppp_package_id;
        $modemTypeId = array_key_exists('modem_type_id', $data) ? $data['modem_type_id'] : $template->modem_type_id;

        $this->assertReferencesAlive($pppPackageId, $modemTypeId);
        $this->assertNoCollision($pppPackageId, $modemTypeId, $template->id);

        $template->update($data);
        $template->markSyncPending();

        return $template->refresh();
    }

    public function delete(WanConfigTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Pesan error jelas SEBELUM query DB dijalankan (bukan menunggu
     * QueryException mentah dari unique constraint) — sesuai instruksi
     * "tampilkan error jelas SN mana bentrok di template mana kalau ada
     * overlap" (di skema ini: kombinasi Paket+Tipe Modem mana yang
     * bentrok, karena SN sendiri dihitung dinamis dari kombinasi ini,
     * bukan diketik manual — lihat WanConfigTemplate's own docblock).
     */
    private function assertNoCollision(int $pppPackageId, ?int $modemTypeId, ?int $ignoreId): void
    {
        $existing = WanConfigTemplate::query()
            ->where('ppp_package_id', $pppPackageId)
            ->where('modem_type_id', $modemTypeId)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();

        if ($existing === null) {
            return;
        }

        $pppPackage = PppPackage::withTrashed()->find($pppPackageId);
        $modemLabel = $modemTypeId === null
            ? 'Default (semua Tipe Modem)'
            : (ModemType::withTrashed()->find($modemTypeId)?->name ?? "Tipe Modem #{$modemTypeId}");

        throw new InvalidArgumentException(
            "Sudah ada template untuk kombinasi Paket \"{$pppPackage?->name}\" x \"{$modemLabel}\" ".
            "(template #{$existing->id}) — satu kombinasi Paket x Tipe Modem hanya boleh punya satu template."
        );
    }

    /**
     * PppPackage/ModemType yang direferensikan HARUS genuinely masih ada
     * dan belum soft-deleted — lihat docblock kelas ini untuk kenapa
     * lookup scoped (bukan withoutGlobalScopes()) sudah cukup.
     */
    private function assertReferencesAlive(int $pppPackageId, ?int $modemTypeId): void
    {
        if (PppPackage::find($pppPackageId) === null) {
            throw new InvalidArgumentException('Paket yang dipilih tidak ditemukan (mungkin sudah dihapus).');
        }

        if ($modemTypeId !== null && ModemType::find($modemTypeId) === null) {
            throw new InvalidArgumentException('Tipe Modem yang dipilih tidak ditemukan (mungkin sudah dihapus).');
        }
    }
}
