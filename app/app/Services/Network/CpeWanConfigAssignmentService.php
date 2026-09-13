<?php

namespace App\Services\Network;

use App\Enums\WanConfigTemplateSource;
use App\Models\CpeDevice;
use App\Models\WanConfigTemplate;
use InvalidArgumentException;

/**
 * v0.12.5 — menulis `cpe_devices.wan_config_template_id`/
 * `wan_config_template_source`. Dua jalur, keduanya lewat class ini
 * (satu sumber kebenaran, BOSS-006):
 * 1. `autoAssignIfUnset()` — dipanggil ON-DEMAND (bukan saat binding v0.7.5,
 *    lihat WanConfigTemplateSuggestionService's own docblock) tiap kali
 *    Detail Perangkat CPE dibuka. Self-healing: kalau device BELUM PERNAH
 *    punya assignment sama sekali (null) DAN suggestion match, langsung
 *    disimpan sebagai source=Auto — tanpa perlu admin klik apa pun. Kalau
 *    device SUDAH punya assignment (auto ATAU manual), tidak pernah
 *    ditimpa — auto-suggest tidak boleh diam-diam menggantikan pilihan
 *    yang sudah ada.
 * 2. `assignManually()` — override eksplisit admin di Detail Perangkat
 *    CPE (dropdown + submit). SELALU menang, terlepas dari apa yang ada
 *    sebelumnya (auto atau manual lain) — ini jalur "koreksi manusia".
 *    `$templateId = null` berarti "hapus assignment" (kembali ke
 *    belum-ter-assign).
 */
class CpeWanConfigAssignmentService
{
    public function __construct(
        private readonly WanConfigTemplateSuggestionService $suggestion,
    ) {}

    public function autoAssignIfUnset(CpeDevice $device): CpeDevice
    {
        if ($device->wan_config_template_id !== null) {
            return $device;
        }

        $suggested = $this->suggestion->suggestFor($device);

        if ($suggested === null) {
            return $device;
        }

        $device->update([
            'wan_config_template_id' => $suggested->id,
            'wan_config_template_source' => WanConfigTemplateSource::Auto,
        ]);

        return $device->fresh();
    }

    public function assignManually(CpeDevice $device, ?int $templateId): CpeDevice
    {
        // Scoped eksplisit ke tenant device ini — mencegah admin tenant A
        // mengassign template milik tenant B (guessable id), bukan cuma
        // mengandalkan TenantScope dari Auth user.
        if ($templateId !== null
            && WanConfigTemplate::withoutGlobalScopes()->where('tenant_id', $device->tenant_id)->find($templateId) === null
        ) {
            throw new InvalidArgumentException('Template yang dipilih tidak ditemukan.');
        }

        $device->update([
            'wan_config_template_id' => $templateId,
            'wan_config_template_source' => $templateId === null ? null : WanConfigTemplateSource::Manual,
        ]);

        return $device->fresh();
    }
}
