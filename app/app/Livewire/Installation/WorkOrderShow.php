<?php

namespace App\Livewire\Installation;

use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.7.5 — first Livewire page for the Installation module (v0.5.0 shipped
 * API-only, no detail page existed before this). Deliberately minimal: just
 * enough for CS/admin to see a work order's scanned devices and record
 * SSID/WiFi password relayed by a technician over phone/personal WhatsApp.
 * This is a BRIDGE UI, not the real field-technician self-service tool —
 * that's still backlog (v0.11.0 Mobile Self-Service Portal, or a future
 * 2-way WhatsApp bot) — see ProvisionWorkOrderDeviceRequest's own docblock
 * for the same note on the API side.
 */
class WorkOrderShow extends Component
{
    use AuthorizesRequests;

    public WorkOrder $work_order;

    public ?int $provisioningDeviceId = null;

    public string $ssid = '';

    public string $wifiPassword = '';

    public function mount(WorkOrder $work_order): void
    {
        $this->authorize('view', $work_order);

        $this->work_order = $work_order;
    }

    public function openProvisioningForm(int $deviceId): void
    {
        $this->authorize('manage', $this->work_order);

        $device = $this->work_order->devices()->findOrFail($deviceId);

        $this->provisioningDeviceId = $deviceId;
        $this->ssid = $device->ssid ?? '';
        // Never prefill a real secret back into the browser — wifi_password
        // is write-only from this form's perspective, same posture as the
        // NAS admin-credential modal (v0.6.5) and the payment gateway
        // settings form (v0.3.5).
        $this->wifiPassword = '';
    }

    public function closeProvisioningForm(): void
    {
        $this->reset(['provisioningDeviceId', 'ssid', 'wifiPassword']);
        $this->resetErrorBag();
    }

    public function saveProvisioning(WorkOrderService $service): void
    {
        $this->authorize('manage', $this->work_order);

        $this->validate([
            'ssid' => ['nullable', 'string', 'max:32'],
            'wifiPassword' => ['nullable', 'string', 'min:8', 'max:63'],
        ]);

        if ($this->ssid === '' && $this->wifiPassword === '') {
            $this->addError('ssid', 'Minimal salah satu dari SSID/password harus diisi.');

            return;
        }

        $device = $this->work_order->devices()->findOrFail($this->provisioningDeviceId);

        // Genuine partial update, matching ProvisionWorkOrderDeviceRequest —
        // an empty field here means "not resupplied this time", not "clear
        // it out". A field left blank because it was already saved earlier
        // stays exactly as it was.
        $data = [];
        if ($this->ssid !== '') {
            $data['ssid'] = $this->ssid;
        }
        if ($this->wifiPassword !== '') {
            $data['wifi_password'] = $this->wifiPassword;
        }

        $service->provisionDeviceWifi($this->work_order, $device, $data);

        session()->flash('status', 'Kredensial WiFi tercatat untuk perangkat ini — akan didorong ke device begitu dikenal GenieACS.');
        $this->closeProvisioningForm();
    }

    public function render()
    {
        $this->work_order->refresh();

        return view('livewire.installation.work-order-show', [
            'devices' => $this->work_order->devices()->with('cpeDevice')->get(),
            'canManage' => auth()->user()->can('manage', $this->work_order),
        ]);
    }
}
