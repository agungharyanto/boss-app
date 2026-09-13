<?php

namespace App\Services\Network;

use App\Models\ModemType;
use App\Models\WanConfigTemplate;
use InvalidArgumentException;

/**
 * v0.12.5 — CRUD sederhana `ModemType` (master data pendukung Template
 * Konfig CPE, dikelola inline lewat modal di halaman yang sama, bukan
 * halaman terpisah — lihat `WanConfigTemplateIndex`).
 */
class ModemTypeService
{
    /**
     * @param  array{name: string, is_active?: bool}  $data
     */
    public function create(array $data): ModemType
    {
        return ModemType::create($data);
    }

    /**
     * @param  array{name?: string, is_active?: bool}  $data
     */
    public function update(ModemType $modemType, array $data): ModemType
    {
        $modemType->update($data);

        return $modemType->refresh();
    }

    /**
     * `restrictOnDelete()` pada `wan_config_templates.modem_type_id` HANYA
     * memblokir hard-delete — ModemType pakai SoftDeletes, jadi
     * `->delete()` polos akan lolos tanpa terhalang FK sama sekali (kelas
     * bug yang sama persis dengan insiden verifikasi v0.12.4, lihat
     * WanConfigTemplateService's own docblock). Cek referential integrity
     * secara EKSPLISIT di sini sebelum soft-delete — pola sama
     * OltDeviceIndex::deleteModel()/deleteManufacturer() (v0.8.1).
     */
    public function delete(ModemType $modemType): void
    {
        $stillUsed = WanConfigTemplate::where('modem_type_id', $modemType->id)->exists();

        if ($stillUsed) {
            throw new InvalidArgumentException(
                "Tipe Modem \"{$modemType->name}\" masih dipakai oleh template Konfig CPE — hapus/ubah template itu dulu."
            );
        }

        $modemType->delete();
    }
}
