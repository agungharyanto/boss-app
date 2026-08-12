<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Server-side processing endpoint for the /cpe-devices DataTables list
 * (v0.7.6-follow-up) — sort/search/pagination all happen here, never by
 * shipping the whole table to the browser. BelongsToResellerScope on
 * CpeDevice already handles reseller-owned-rows-only scoping automatically,
 * same as every other CPE endpoint in this codebase — no manual filter
 * needed here either.
 *
 * MAC/RX/uptime are resolved per-row via
 * App\Services\Network\CpeParameterResolverService::resolveDeviceSummary()
 * — the SAME mechanism the old Livewire list and the Detail panel both
 * already used, no new data source. Deliberately NOT sortable (see
 * $unorderableColumns) — these values live in GenieACS, not this table, so
 * sorting by them server-side would mean resolving every device on every
 * page (one GenieACS HTTP call each) before pagination could even happen,
 * defeating the entire point of server-side processing. "Online Duration"
 * IS sortable despite being a derived/humanized display value — it maps
 * straight to the real status_changed_at column via orderColumn() below.
 */
class CpeDeviceDatatableController extends Controller
{
    /** @var array<int, array{mac_address: ?string, rx_power_dbm: ?float, tx_power_dbm: ?float, device_uptime_seconds: ?float}> */
    private array $summaryCache = [];

    public function __invoke(Request $request, CpeParameterResolverService $resolver): JsonResponse
    {
        $this->authorize('viewAny', CpeDevice::class);

        // Narrow eager-loads (id+name only) — the customer relation
        // otherwise carries nik (an `encrypted` cast, decrypted to
        // plaintext the moment it's accessed/serialized) and other PII that
        // has no business ever reaching this JSON response.
        $query = CpeDevice::query()->with(['customer:id,name', 'reseller:id,name']);

        return DataTables::eloquent($query)
            ->addColumn('id', fn (CpeDevice $d) => $d->id)
            ->addColumn('customer_name', fn (CpeDevice $d) => $d->customer?->name ?? '—')
            ->addColumn('reseller_name', fn (CpeDevice $d) => $d->reseller?->name)
            ->addColumn('mac_address', fn (CpeDevice $d) => $this->summaryFor($d, $resolver)['mac_address'])
            ->addColumn('rx_power_dbm', fn (CpeDevice $d) => $this->summaryFor($d, $resolver)['rx_power_dbm'])
            ->addColumn('device_uptime_seconds', fn (CpeDevice $d) => $this->summaryFor($d, $resolver)['device_uptime_seconds'])
            ->addColumn('online_duration_text', function (CpeDevice $d) {
                return $d->status->value === 'online' && $d->status_changed_at
                    ? $d->status_changed_at->diffForHumans(null, true)
                    : '-';
            })
            ->addColumn('status_value', fn (CpeDevice $d) => $d->status->value)
            ->addColumn('status_label', fn (CpeDevice $d) => $d->status->label())
            ->orderColumn('customer_name', function ($query, $order) {
                $query->orderBy(
                    Customer::query()->withoutGlobalScopes()->select('name')
                        ->whereColumn('customers.id', 'cpe_devices.customer_id'),
                    $order
                );
            })
            ->orderColumn('online_duration_text', fn ($query, $order) => $query->orderBy('status_changed_at', $order))
            ->filter(function ($query) use ($request) {
                $keyword = (string) ($request->input('search.value') ?? '');

                if ($keyword === '') {
                    return;
                }

                // Same LOWER()+LIKE portability reasoning as every other
                // search in this codebase — Postgres LIKE is case-sensitive
                // by default, and this stays sqlite-compatible too.
                $needle = '%'.mb_strtolower($keyword).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(serial_number) LIKE ?', [$needle])
                    ->orWhereHas('customer', fn ($q2) => $q2->whereRaw('LOWER(name) LIKE ?', [$needle])));
            })
            // Explicit output whitelist — WITHOUT this, yajra serializes the
            // full underlying model plus eager-loaded relations into the
            // JSON response, which would leak the customer's decrypted nik
            // (and everything else on the row) to the browser. Only these
            // fields, ever.
            ->only([
                'id', 'customer_name', 'reseller_name', 'manufacturer', 'model_name',
                'serial_number', 'mac_address', 'rx_power_dbm', 'device_uptime_seconds',
                'online_duration_text', 'status_value', 'status_label',
            ])
            ->make(true);
    }

    /**
     * @return array{mac_address: ?string, rx_power_dbm: ?float, tx_power_dbm: ?float, device_uptime_seconds: ?float}
     */
    private function summaryFor(CpeDevice $device, CpeParameterResolverService $resolver): array
    {
        return $this->summaryCache[$device->id] ??= $resolver->resolveDeviceSummary($device->genieacs_device_id);
    }
}
