<?php

namespace App\Livewire\Network;

use App\Enums\MikrotikSyncStatus;
use App\Models\RemoteWanConfig;
use App\Services\Network\GenieAcsPresetService;
use App\Services\Network\RemoteWanConfigService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * "Konfig Remote" (`/remote-config`) — GenieACS Auto-WAN configurable.
 * VLAN + username/password default PPPoE yang dulu HARDCODED di provision
 * script referensi (`const targetVlan = 1000`) sekarang dari sini.
 *
 * Singleton (`RemoteWanConfig` id=1). Simpan → `RemoteWanConfigService`
 * men-dispatch `SyncRemoteWanConfigToGenieAcsJob` (async, push ke
 * genieacs-nbi lewat `GenieAcsPresetService`). Badge status GenieACS
 * (Pending/Tersinkron/Gagal) di-poll conditional sama pola
 * `CustomerIpPoolIndex`.
 */
class RemoteConfigSettings extends Component
{
    use AuthorizesRequests;

    #[Validate('boolean')]
    public bool $enabled = false;

    #[Validate('boolean')]
    public bool $wan1_enabled = true;

    #[Validate('required|integer|min:1|max:4094')]
    public int $wan1_vlan = 1000;

    #[Validate('required|string|min:1|max:64')]
    public string $wan1_pppoe_username = 'default';

    #[Validate('required|string|min:1|max:64')]
    public string $wan1_pppoe_password = 'default';

    /** SN allowlist WAN1, satu per baris. Kosong = fleet-wide (precondition "true"). */
    #[Validate('nullable|string|max:20000')]
    public string $wan1_serial_allowlist = '';

    #[Validate('boolean')]
    public bool $wan2_enabled = false;

    #[Validate('required|integer|min:1|max:4094')]
    public int $wan2_vlan = 1200;

    /** SN allowlist WAN2, satu per baris. Kosong = izinkan semua. */
    #[Validate('nullable|string|max:20000')]
    public string $wan2_serial_allowlist = '';

    public ?string $flash = null;

    /** State efektif di GenieACS live (diisi mount/refresh, null kalau genieacs-nbi tak terjangkau). */
    public ?array $liveState = null;

    public function mount(RemoteWanConfigService $service, GenieAcsPresetService $presets): void
    {
        $this->authorize('view', RemoteWanConfig::class);

        $this->syncFromModel($service->current());
        $this->loadLiveState($presets);
    }

    private function syncFromModel(RemoteWanConfig $config): void
    {
        $this->enabled = (bool) $config->enabled;
        $this->wan1_enabled = (bool) $config->wan1_enabled;
        $this->wan1_vlan = (int) $config->wan1_vlan;
        $this->wan1_pppoe_username = (string) $config->wan1_pppoe_username;
        $this->wan1_pppoe_password = (string) $config->wan1_pppoe_password;
        $this->wan1_serial_allowlist = (string) $config->wan1_serial_allowlist;
        $this->wan2_enabled = (bool) $config->wan2_enabled;
        $this->wan2_vlan = (int) $config->wan2_vlan;
        $this->wan2_serial_allowlist = (string) $config->wan2_serial_allowlist;
    }

    private function loadLiveState(GenieAcsPresetService $presets): void
    {
        try {
            $this->liveState = $presets->inspectAutoWanState();
        } catch (Throwable) {
            $this->liveState = null;
        }
    }

    public function save(RemoteWanConfigService $service, GenieAcsPresetService $presets): void
    {
        $this->authorize('manage', RemoteWanConfig::class);

        $validated = $this->validate();

        $service->save([
            'enabled' => $validated['enabled'],
            'wan1_enabled' => $validated['wan1_enabled'],
            'wan1_vlan' => $validated['wan1_vlan'],
            'wan1_pppoe_username' => $validated['wan1_pppoe_username'],
            'wan1_pppoe_password' => $validated['wan1_pppoe_password'],
            'wan1_serial_allowlist' => $validated['wan1_serial_allowlist'] ?: null,
            'wan2_enabled' => $validated['wan2_enabled'],
            'wan2_vlan' => $validated['wan2_vlan'],
            'wan2_serial_allowlist' => $validated['wan2_serial_allowlist'] ?: null,
        ], auth()->user());

        $this->flash = 'Konfigurasi disimpan. Sinkronisasi ke GenieACS berjalan di latar belakang '
            .'(nilai berlaku di perangkat dalam ~5 menit; provision baru mungkin butuh restart genieacs-cwmp — lihat catatan di bawah).';

        $this->loadLiveState($presets);
    }

    public function resync(RemoteWanConfigService $service, GenieAcsPresetService $presets): void
    {
        $this->authorize('manage', RemoteWanConfig::class);

        $service->resync();
        $this->flash = 'Sinkronisasi ulang ke GenieACS dijadwalkan.';
        $this->loadLiveState($presets);
    }

    public function render(RemoteWanConfigService $service)
    {
        $config = $service->current();

        return view('livewire.network.remote-config-settings', [
            'config' => $config,
            'canManage' => auth()->user()->can('manage', RemoteWanConfig::class),
            'hasPendingSync' => $config->genieacs_sync_status === MikrotikSyncStatus::Pending,
        ])->layout('layouts.app');
    }
}
