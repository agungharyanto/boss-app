<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\CpeActionLog;
use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Contracts\View\View;

/**
 * Renders the DataTables child-row content (v0.7.6-follow-up) — everything
 * that used to live in the old modal-based "Detail" view (data grid,
 * Reboot/Ganti WiFi/Ganti Modem/Remove forms, Riwayat Aksi, Client/
 * connected-hosts) in one server-rendered HTML fragment, injected into the
 * expanded row by the page's own JS. A plain Blade partial rather than an
 * embedded Livewire component — Livewire's hydration model expects a
 * component to be present at initial page load or mounted via Livewire's
 * own morphing, not injected ad-hoc into a DOM node DataTables created
 * after the fact.
 */
class CpeDeviceDetailController extends Controller
{
    public function show(CpeDevice $cpeDevice, CpeParameterResolverService $resolver): View
    {
        $this->authorize('view', $cpeDevice);

        $cpeDevice->load(['customer', 'reseller']);

        $summary = $resolver->resolveDeviceSummary($cpeDevice->genieacs_device_id);

        $historyLogs = CpeActionLog::query()
            ->where('cpe_device_id', $cpeDevice->id)
            ->with('performedBy')
            ->latest()
            ->limit(20)
            ->get();

        $connectedHosts = CpeConnectedHost::query()
            ->where('cpe_device_id', $cpeDevice->id)
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        return view('cpe-devices.detail-row', [
            'device' => $cpeDevice,
            'summary' => $summary,
            'historyLogs' => $historyLogs,
            'connectedHosts' => $connectedHosts,
            'canManage' => auth()->user()->can('manage', $cpeDevice),
        ]);
    }
}
