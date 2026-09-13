<?php

namespace App\Services\Network;

use App\Enums\ModemTypeAssignmentSource;
use App\Models\CpeDevice;
use App\Models\ModemType;
use InvalidArgumentException;

/**
 * v0.12.5 (rename dari CpeWanConfigAssignmentService — sekarang assign
 * Tipe Modem, bukan Template) — menulis `cpe_devices.modem_type_id`/
 * `modem_type_source`. Dua jalur, keduanya lewat class ini (satu sumber
 * kebenaran, BOSS-006):
 * 1. `autoAssignIfUnset()` — dipanggil ON-DEMAND (tiap kali Detail
 *    Perangkat CPE dibuka). Self-healing: kalau device BELUM PERNAH
 *    punya assignment sama sekali (null) DAN suggestion (OUI matching,
 *    lihat ModemTypeSuggestionService) match, langsung disimpan sebagai
 *    source=Auto — tanpa perlu admin klik apa pun. Kalau device SUDAH
 *    punya assignment (auto ATAU manual), tidak pernah ditimpa —
 *    auto-suggest tidak boleh diam-diam menggantikan pilihan yang sudah
 *    ada.
 * 2. `assignManually()` — override eksplisit admin di Detail Perangkat
 *    CPE (dropdown + submit). SELALU menang, terlepas dari apa yang ada
 *    sebelumnya (auto atau manual lain) — ini jalur "koreksi manusia".
 *    `$modemTypeId = null` berarti "hapus assignment" (kembali ke
 *    belum-ter-assign).
 */
class CpeModemTypeAssignmentService
{
    public function __construct(
        private readonly ModemTypeSuggestionService $suggestion,
    ) {}

    public function autoAssignIfUnset(CpeDevice $device): CpeDevice
    {
        if ($device->modem_type_id !== null) {
            return $device;
        }

        $suggested = $this->suggestion->suggestFor($device);

        if ($suggested === null) {
            return $device;
        }

        $device->update([
            'modem_type_id' => $suggested->id,
            'modem_type_source' => ModemTypeAssignmentSource::Auto,
        ]);

        return $device->fresh();
    }

    public function assignManually(CpeDevice $device, ?int $modemTypeId): CpeDevice
    {
        // Scoped eksplisit ke tenant device ini — mencegah admin tenant A
        // mengassign Tipe Modem milik tenant B (guessable id), bukan cuma
        // mengandalkan TenantScope dari Auth user.
        if ($modemTypeId !== null
            && ModemType::withoutGlobalScopes()->where('tenant_id', $device->tenant_id)->find($modemTypeId) === null
        ) {
            throw new InvalidArgumentException('Tipe Modem yang dipilih tidak ditemukan.');
        }

        $device->update([
            'modem_type_id' => $modemTypeId,
            'modem_type_source' => $modemTypeId === null ? null : ModemTypeAssignmentSource::Manual,
        ]);

        return $device->fresh();
    }
}
