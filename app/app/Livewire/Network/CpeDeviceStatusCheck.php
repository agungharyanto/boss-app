<?php

namespace App\Livewire\Network;

use App\Services\Network\CpeDeviceDiagnosticService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Admin-only self-service diagnostic page ("Cek Status Device") — lets
 * Agung check why a specific device/customer hasn't shown up in
 * /cpe-devices without a manual tinker session every time. Gated on
 * cpe_devices.view directly (not the CpeDevicePolicy::viewAny() a reseller
 * can also satisfy) — this exposes legacy-import/matching internals
 * (legacy_mac_customer_map rows, raw GenieACS device ids) that are
 * operationally useful for ISP staff but not reseller-facing.
 */
class CpeDeviceStatusCheck extends Component
{
    use AuthorizesRequests;

    public string $serialNumber = '';

    public string $phoneNumber = '';

    public bool $hasSearched = false;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('cpe_devices.view');
    }

    public function check(CpeDeviceDiagnosticService $service): void
    {
        $this->authorize('cpe_devices.view');

        $this->validate([
            'serialNumber' => ['nullable', 'string', 'max:255'],
            'phoneNumber' => ['nullable', 'string', 'max:255'],
        ]);

        if (trim($this->serialNumber) === '' && trim($this->phoneNumber) === '') {
            $this->addError('serialNumber', 'Isi minimal salah satu: Serial Number atau Nomor HP.');

            return;
        }

        $this->result = $service->diagnose(
            $this->serialNumber !== '' ? $this->serialNumber : null,
            $this->phoneNumber !== '' ? $this->phoneNumber : null,
        );
        $this->hasSearched = true;
    }

    public function reset(...$properties): void
    {
        $this->serialNumber = '';
        $this->phoneNumber = '';
        $this->result = null;
        $this->hasSearched = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.network.cpe-device-status-check');
    }
}
