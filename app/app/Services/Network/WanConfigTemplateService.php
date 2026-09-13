<?php

namespace App\Services\Network;

use App\Models\ModemType;
use App\Models\WanConfigTemplate;
use InvalidArgumentException;

/**
 * v0.12.5 (revisi arsitektur) — CRUD `WanConfigTemplate`. Template TIDAK
 * terikat Paket sama sekali, dibedakan HANYA oleh Tipe Modem — TIDAK ADA
 * lagi validasi collision (Paket, Modem) seperti desain asli v0.12.4;
 * beberapa Template boleh punya Tipe Modem yang sama (dibedakan lewat
 * `name` bebas). Satu-satunya validasi yang tersisa: referential
 * integrity — Tipe Modem yang dipilih harus genuinely masih ada & belum
 * soft-deleted, pola `assertReferencesAlive()` yang sama persis dengan
 * versi sebelumnya (masih relevan, tidak berubah cara kerjanya).
 *
 * TIDAK ADA dispatch job sync ke GenieACS di sini — GenieAcsPresetService
 * per-template (v0.12.6) belum dibangun.
 */
class WanConfigTemplateService
{
    /**
     * @param  array{name: string, modem_type_id: ?int, enabled?: bool, wan1_enabled?: bool, wan1_vlan?: int, wan1_pppoe_username?: string, wan1_pppoe_password?: string, wan2_enabled?: bool, wan2_vlan?: int}  $data
     */
    public function create(array $data): WanConfigTemplate
    {
        $this->assertReferencesAlive($data['modem_type_id'] ?? null);

        return WanConfigTemplate::create($data);
    }

    /**
     * @param  array{name?: string, modem_type_id?: ?int, enabled?: bool, wan1_enabled?: bool, wan1_vlan?: int, wan1_pppoe_username?: string, wan1_pppoe_password?: string, wan2_enabled?: bool, wan2_vlan?: int}  $data
     */
    public function update(WanConfigTemplate $template, array $data): WanConfigTemplate
    {
        $modemTypeId = array_key_exists('modem_type_id', $data) ? $data['modem_type_id'] : $template->modem_type_id;

        $this->assertReferencesAlive($modemTypeId);

        $template->update($data);
        $template->markSyncPending();

        return $template->refresh();
    }

    public function delete(WanConfigTemplate $template): void
    {
        $template->delete();
    }

    /**
     * ModemType yang direferensikan HARUS genuinely masih ada dan belum
     * soft-deleted — lookup SCOPED (bukan withoutGlobalScopes()) sudah
     * cukup, SoftDeletingScope Eloquent otomatis mengecualikan baris yang
     * sudah soft-deleted. Pelajaran langsung dari insiden verifikasi
     * v0.12.4 (PppPackage #17 produksi ter-soft-delete tak sengaja saat
     * menguji restrictOnDelete()) — restrictOnDelete() FK HANYA memblokir
     * hard-delete, tidak soft-delete, jadi tidak boleh diandalkan sendirian.
     */
    private function assertReferencesAlive(?int $modemTypeId): void
    {
        if ($modemTypeId !== null && ModemType::find($modemTypeId) === null) {
            throw new InvalidArgumentException('Tipe Modem yang dipilih tidak ditemukan (mungkin sudah dihapus).');
        }
    }
}
