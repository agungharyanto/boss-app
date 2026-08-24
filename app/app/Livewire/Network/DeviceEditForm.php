<?php

namespace App\Livewire\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * v0.8.4 Bagian D — Edit modal for DeviceMonitoringList, same sibling-
 * component-via-dispatched-event architecture as DeviceHistoryModal
 * (device-edit-requested → this component), not bolted onto
 * DeviceMonitoringList's own state.
 *
 * Only exposes the small whitelist LibreNmsService::updateDevice() itself
 * accepts (`display_template`/`community`/`port`/`snmpver`) — see that
 * method's own docblock for why `hostname`/`ip`/SNMPv3 fields are
 * deliberately excluded. `requires('monitoring.manage')`, not `.view` —
 * this mutates LibreNMS state, same posture as AddMonitoringDeviceForm.
 */
class DeviceEditForm extends Component
{
    use AuthorizesRequests;

    public bool $showModal = false;

    public ?int $deviceId = null;

    public string $hostname = '';

    public string $displayName = '';

    public string $community = '';

    public int $port = 161;

    public string $snmpVersion = 'v2c';

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->authorize('monitoring.manage');
    }

    #[On('device-edit-requested')]
    public function open(int $deviceId, ?LibreNmsService $service = null): void
    {
        $this->authorize('monitoring.manage');

        $service ??= app(LibreNmsService::class);
        $this->errorMessage = null;
        $this->successMessage = null;

        try {
            $device = $service->getEditableDevice($deviceId);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            $this->showModal = true;

            return;
        }

        if ($device === null) {
            $this->errorMessage = "Device #{$deviceId} tidak ditemukan.";
            $this->showModal = true;

            return;
        }

        $this->deviceId = $device['device_id'];
        $this->hostname = $device['hostname'];
        $this->displayName = $device['display_template'] ?? '';
        $this->community = $device['community'] ?? '';
        $this->port = $device['port'];
        $this->snmpVersion = $device['snmpver'];
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function save(LibreNmsService $service): void
    {
        $this->authorize('monitoring.manage');

        $this->errorMessage = null;
        $this->successMessage = null;

        $validated = $this->validate([
            'displayName' => ['nullable', 'string', 'max:255'],
            'community' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'snmpVersion' => ['required', 'in:v1,v2c'],
        ]);

        try {
            $service->updateDevice($this->deviceId, [
                'display_template' => $validated['displayName'] ?? '',
                'community' => $validated['community'],
                'port' => $validated['port'],
                'snmpver' => $validated['snmpVersion'],
            ]);
        } catch (LibreNmsDataUnavailableException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->successMessage = 'Device berhasil diperbarui.';
        $this->dispatch('monitoring-device-updated');
    }

    public function render()
    {
        return view('livewire.network.device-edit-form');
    }
}
