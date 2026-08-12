<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.7.1: read-only. v0.7.4 adds remote actions. v0.7.6 adds connected
 * clients.
 *
 * v0.7.6-follow-up (DataTables rewrite): the device LIST itself is no
 * longer rendered by this Livewire component — it's a real DataTables
 * table fed by GET /api/internal/cpe-devices/datatable
 * (App\Http\Controllers\Api\Internal\CpeDeviceDatatableController, yajra
 * server-side processing), and every row action (Reboot/Riwayat/Ganti
 * WiFi/Ganti Modem/Remove/Client) lives in the DataTables child row,
 * powered by plain fetch() calls against
 * App\Http\Controllers\Api\Internal\CpeDeviceActionController +
 * CpeDeviceDetailController — both reuse the exact same
 * CpeActionService/CpeBindingService/CpeParameterResolverService this
 * component used to call directly. This class is now just the page
 * shell: the auth gate (mount()) and the auto-reload interval dropdown's
 * options — the reload itself is a plain `table.ajax.reload()` on a
 * client-side setInterval, not wire:poll, since the table data doesn't
 * come from this component's own render() anymore.
 */
class CpeDeviceIndex extends Component
{
    use AuthorizesRequests;

    /**
     * @return array<string, string>
     */
    public function pollIntervalOptions(): array
    {
        return [
            'off' => 'Off',
            '5' => '5 detik',
            '10' => '10 detik',
            '30' => '30 detik',
            '60' => '60 detik',
            '300' => '5 menit',
        ];
    }

    public function mount(): void
    {
        $this->authorize('viewAny', CpeDevice::class);
    }

    public function render()
    {
        return view('livewire.network.cpe-device-index');
    }
}
