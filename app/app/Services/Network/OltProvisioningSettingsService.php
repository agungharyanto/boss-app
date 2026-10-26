<?php

namespace App\Services\Network;

use App\Models\OltDevice;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * v0.23.8.6a — simpan setelan "Provisioning" per-OLT (admission mode + channel
 * flags). Satu-satunya penulis `olt_devices.requires_bind_onu`/
 * `omci_channel_enabled`/`tr069_channel_enabled` dari UI (BOSS-006: logic di
 * service, bukan komponen Livewire).
 *
 * Nilai ini dibaca RUNTIME oleh `CtcomOltAdapter::admit()` (requires_bind_onu)
 * dan pre-check kanal `ZtpOrchestratorService` (omci/tr069_channel_enabled) —
 * keduanya membaca KOLOM dari model yang di-load fresh per request, jadi selalu
 * nilai terbaru dari DB (nol cache).
 */
class OltProvisioningSettingsService
{
    /**
     * OLT ini pakai paradigma uncfg (ZTE/Huawei) → `requires_bind_onu` TIDAK
     * berlaku (null, pakai daftar uncfg). Diturunkan dari VENDOR (bukan nilai
     * kolom saat ini, yang bisa null karena sebab lain) supaya stabil.
     */
    public function usesUncfgParadigm(OltDevice $olt): bool
    {
        try {
            return OltDevice::defaultRequiresBindOnu($olt->sidecarVendorKey()) === null;
        } catch (\Throwable $e) {
            // Vendor tak dikenal sidecar → requires_bind_onu tak berlaku (disabled).
            return true;
        }
    }

    /**
     * @throws InvalidArgumentException kalau kedua channel dimatikan.
     */
    public function update(
        OltDevice $olt,
        ?bool $requiresBindOnu,
        bool $omciEnabled,
        bool $tr069Enabled,
        ?int $actorId,
    ): OltDevice {
        if (! $omciEnabled && ! $tr069Enabled) {
            throw new InvalidArgumentException('Minimal satu channel (OMCI atau TR-069) harus aktif.');
        }

        $before = [
            'requires_bind_onu' => $olt->requires_bind_onu,
            'omci_channel_enabled' => $olt->omci_channel_enabled,
            'tr069_channel_enabled' => $olt->tr069_channel_enabled,
        ];

        $data = [
            'omci_channel_enabled' => $omciEnabled,
            'tr069_channel_enabled' => $tr069Enabled,
        ];
        // requires_bind_onu HANYA diubah untuk OLT bind-onu-paradigm (CT-COM);
        // uncfg-paradigm (ZTE/Huawei) dibiarkan null, tak bisa diubah dari UI.
        if (! $this->usesUncfgParadigm($olt)) {
            $data['requires_bind_onu'] = $requiresBindOnu;
        }

        $olt->forceFill($data)->save();

        Log::info('olt.provisioning_settings.updated', [
            'olt_id' => $olt->id,
            'olt' => $olt->name,
            'actor_id' => $actorId,
            'before' => $before,
            'after' => [
                'requires_bind_onu' => $olt->requires_bind_onu,
                'omci_channel_enabled' => $olt->omci_channel_enabled,
                'tr069_channel_enabled' => $olt->tr069_channel_enabled,
            ],
        ]);

        return $olt;
    }
}
