<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Services\Network\RadiusSessionHistoryService;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * v0.8.4 — "Riwayat Dialup" section on the CPE detail page (Acct ID,
 * Uptime, Waktu Mulai/Berakhir, NAS, Upload, Download, Terminate By —
 * matching the MixRadius reference layout), backed by
 * RadiusSessionHistoryService (radacct via the separate `radius`
 * connection — see that service's own docblock for the full username-
 * mapping/empty-state reasoning). Self-authorizes independently
 * (`CpeDevice::findOrFail()` — tenant-scoped, so a cross-tenant id 404s
 * before the policy check even runs — then `$this->authorize('view',
 * $device)`), same defense-in-depth posture as every other Livewire
 * component on this page (CpeSignalHistoryGraph).
 *
 * Only one empty state, not two: `cpe_devices.customer_id` is NOT NULL
 * (every CPE device is required to have a customer, confirmed directly
 * from its own migration — `foreignId('customer_id')->constrained()`, no
 * `->nullable()`) — so "has zero radacct rows" (never migrated to RADIUS,
 * or genuinely hasn't dialed since accounting was re-enabled — both look
 * the same from here, see RadiusSessionHistoryService's own docblock for
 * why that's fine, not something this UI needs to distinguish) is the
 * ONLY empty state this component needs to render; there is no "device
 * has no customer at all" case to defend against.
 */
class CpeDialupHistory extends Component
{
    use AuthorizesRequests;

    public int $cpeDeviceId;

    /** @var array<int, array{acct_id: int, started_at: ?Carbon, stopped_at: ?Carbon, is_active: bool, session_seconds: int, nas_ip: ?string, upload_bytes: int, download_bytes: int, terminate_cause: ?string}> */
    public array $rows = [];

    public function mount(int $cpeDeviceId, ?RadiusSessionHistoryService $service = null): void
    {
        $device = CpeDevice::findOrFail($cpeDeviceId);
        $this->authorize('view', $device);

        $this->cpeDeviceId = $cpeDeviceId;

        // withoutGlobalScopes(), not $device->customer — CpeDevice itself
        // was already fetched tenant-scoped above (proving the acting
        // request is entitled to see it), so its own customer_id is
        // trustworthy without re-applying Customer's BelongsToTenant scope
        // a second time. Same "derive tenant_id from customer_id, not the
        // other way around" trust relationship CpeDeviceFactory's own
        // definition already relies on.
        $customer = Customer::withoutGlobalScopes()->findOrFail($device->customer_id);

        $service ??= app(RadiusSessionHistoryService::class);
        $this->rows = $service->getHistoryForCustomer($customer);
    }

    public function formatBytes(int $bytes): string
    {
        return app(RadiusSessionHistoryService::class)->formatBytes($bytes);
    }

    public function formatDuration(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true]);
    }

    public function render()
    {
        return view('livewire.network.cpe-dialup-history');
    }
}
