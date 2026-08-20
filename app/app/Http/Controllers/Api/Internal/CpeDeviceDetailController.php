<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\CpeActionLog;
use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Renders CPE device detail content — originally only the DataTables
 * child-row fragment (v0.7.6-follow-up), now ALSO the full standalone
 * `/cpe-devices/{id}` page (2026-08-16, replacing the child-row expand
 * interaction entirely — see routes/web.php). Both share the exact same
 * data-gathering (loadDetailData()) and the exact same
 * cpe-devices.detail-row partial for the actions/riwayat/connected-hosts
 * section — the full page just wraps it with additional sections
 * (Attached VLANs, PPPoE, WiFi/SSID table, Ethernet ports) that the old
 * child row never had room for. A plain Blade partial rather than an
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

        return view('cpe-devices.detail-row', $this->loadDetailData($cpeDevice, $resolver));
    }

    /**
     * Full standalone detail page (2026-08-16) — reuses loadDetailData()
     * for everything the old child row already showed, and additionally
     * resolves Attached VLANs / PPPoE username / WiFi SSID list / Ethernet
     * ports straight from the device's own TR-069 tree. PPPoE PASSWORD is
     * deliberately NOT resolved/passed here — see pppoePassword() below,
     * the page never receives it even server-side until explicitly
     * requested by a reveal click.
     */
    public function page(CpeDevice $cpeDevice, CpeParameterResolverService $resolver): View
    {
        $this->authorize('view', $cpeDevice);

        $genieAcsId = $cpeDevice->genieacs_device_id;
        $pppoe = $resolver->resolvePppoeConnection($genieAcsId);

        $pageData = array_merge(
            $this->loadDetailData($cpeDevice, $resolver),
            [
                'wanConnections' => $resolver->resolveWanConnectionsSummary($genieAcsId),
                'pppoeName' => $pppoe['name'] ?? null,
                'pppoeUsername' => $pppoe['username'] ?? null,
                'wlanConfigurations' => $resolver->resolveWlanConfigurations($genieAcsId),
                'ethernetPorts' => $resolver->resolveEthernetPorts($genieAcsId),
            ],
        );

        // Every other page in this app is a Livewire full-page component,
        // which Livewire itself wraps with `layouts::app` (config('livewire')'s
        // `component_namespaces.layouts` maps to resources/views/layouts,
        // `component_layout` defaults to `layouts::app`) — this route is a
        // plain Controller-returned View instead. `cpe-devices.page` is a
        // thin glue view that reproduces that same wrapping WITHOUT calling
        // ->render() here as a separate top-level statement — see its own
        // docblock for the real Illuminate\View\Factory::renderCount pitfall
        // (confirmed via Playwright, not caught by PHPUnit) that an earlier
        // version of this method hit by rendering cpe-devices.show and
        // layouts.app as two independent top-level render() calls.
        return view('cpe-devices.page', [
            'title' => 'Detail Perangkat CPE — '.$cpeDevice->serial_number,
            'pageData' => $pageData,
        ]);
    }

    /**
     * On-demand PPPoE password reveal (2026-08-16) — the ONLY place this
     * value is ever sent to the browser, and only after an explicit click,
     * never embedded in the page's initial HTML. Mirrors the operational
     * (not platform-secret) posture already established for this value:
     * unlike Xendit/RADIUS credentials (never re-sent at all, masked
     * boolean only), a PPPoE password is the CUSTOMER's own credential that
     * CS/NOC staff have a legitimate, routine reason to read back — so a
     * real reveal is appropriate here, just not a plaintext dump on page
     * load.
     */
    public function pppoePassword(CpeDevice $cpeDevice, CpeParameterResolverService $resolver): JsonResponse
    {
        $this->authorize('view', $cpeDevice);

        $pppoe = $resolver->resolvePppoeConnection($cpeDevice->genieacs_device_id);
        $password = $pppoe['password'] ?? null;

        return response()->json([
            'password' => is_string($password) && $password !== '' ? $password : null,
        ]);
    }

    /**
     * @return array{device: CpeDevice, summary: array, historyLogs: Collection, connectedHosts: Collection, canManage: bool}
     */
    private function loadDetailData(CpeDevice $cpeDevice, CpeParameterResolverService $resolver): array
    {
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

        return [
            'device' => $cpeDevice,
            'summary' => $summary,
            'historyLogs' => $historyLogs,
            'connectedHosts' => $connectedHosts,
            'canManage' => auth()->user()->can('manage', $cpeDevice),
        ];
    }
}
