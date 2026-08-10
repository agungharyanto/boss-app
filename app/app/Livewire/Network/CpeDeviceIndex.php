<?php

namespace App\Livewire\Network;

use App\Enums\CpeActionStatus;
use App\Models\CpeActionLog;
use App\Models\CpeDevice;
use App\Services\Network\CpeActionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.7.1: read-only. v0.7.4 adds remote actions (reboot, ganti WiFi) + a
 * per-device action history panel — `manage()` on CpeDevicePolicy gates
 * both new buttons, `view()` still gates the history panel (same as the
 * list itself). BelongsToResellerScope on CpeDevice already handles list
 * scoping automatically, same as NasIndex — no manual filter needed here.
 *
 * Every action here is deliberately "not instant" in its own MESSAGING even
 * though the underlying service always tries GenieACS's connection_request
 * too — see App\Services\Network\CpeActionService's own docblock for why
 * (v0.7.3's Connection Request routing is implemented but not yet confirmed
 * working end-to-end against real hardware). Never render "berhasil"/
 * "done" wording here for a `delivered` result — only "terkirim, diterapkan
 * saat perangkat terhubung berikutnya".
 */
class CpeDeviceIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public ?int $wifiDeviceId = null;

    public string $wifiSsid = '';

    public string $wifiPassword = '';

    public bool $showWifiModal = false;

    public ?int $historyDeviceId = null;

    public bool $showHistoryModal = false;

    public function mount(): void
    {
        $this->authorize('viewAny', CpeDevice::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function reboot(int $deviceId, CpeActionService $service): void
    {
        $device = CpeDevice::findOrFail($deviceId);
        $this->authorize('manage', $device);

        $log = $service->reboot($device, auth()->user());

        $this->flashActionResult($log);
    }

    public function openWifiModal(int $deviceId): void
    {
        $device = CpeDevice::findOrFail($deviceId);
        $this->authorize('manage', $device);

        $this->wifiDeviceId = $deviceId;
        $this->wifiSsid = '';
        $this->wifiPassword = '';
        $this->showWifiModal = true;
    }

    public function closeWifiModal(): void
    {
        $this->showWifiModal = false;
        $this->wifiDeviceId = null;
        $this->wifiSsid = '';
        $this->wifiPassword = '';
        $this->resetErrorBag();
    }

    public function submitWifi(CpeActionService $service): void
    {
        $this->validate([
            'wifiSsid' => ['nullable', 'string', 'max:32', 'required_without:wifiPassword'],
            'wifiPassword' => ['nullable', 'string', 'min:8', 'max:63', 'required_without:wifiSsid'],
        ]);

        $device = CpeDevice::findOrFail($this->wifiDeviceId);
        $this->authorize('manage', $device);

        $log = $service->setWifiCredentials(
            $device,
            $this->wifiSsid !== '' ? $this->wifiSsid : null,
            $this->wifiPassword !== '' ? $this->wifiPassword : null,
            auth()->user(),
        );

        $this->closeWifiModal();
        $this->flashActionResult($log);
    }

    public function openHistoryModal(int $deviceId): void
    {
        $device = CpeDevice::findOrFail($deviceId);
        $this->authorize('view', $device);

        $this->historyDeviceId = $deviceId;
        $this->showHistoryModal = true;
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
        $this->historyDeviceId = null;
    }

    private function flashActionResult(CpeActionLog $log): void
    {
        if ($log->status === CpeActionStatus::Delivered) {
            session()->flash('status', 'Perintah terkirim — akan diterapkan saat perangkat terhubung berikutnya. Ini BUKAN konfirmasi perangkat sudah menjalankannya.');

            return;
        }

        session()->flash('actionError', 'Perintah GAGAL dikirim: '.$log->failed_reason);
    }

    public function render()
    {
        $devices = CpeDevice::query()
            ->with(['customer', 'reseller'])
            ->when(
                $this->search,
                fn ($query) => $query->where('serial_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            )
            ->latest()
            ->paginate(15);

        $historyLogs = $this->historyDeviceId !== null
            ? CpeActionLog::query()
                ->where('cpe_device_id', $this->historyDeviceId)
                ->with('performedBy')
                ->latest()
                ->limit(20)
                ->get()
            : collect();

        return view('livewire.network.cpe-device-index', [
            'devices' => $devices,
            'historyLogs' => $historyLogs,
        ]);
    }
}
