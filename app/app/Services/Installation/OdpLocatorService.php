<?php

namespace App\Services\Installation;

use App\Enums\OdpPortStatus;
use App\Events\OdpCapacityExhausted;
use App\Models\Customer;
use App\Models\OdpPort;

class OdpLocatorService
{
    /**
     * Haversine distance in kilometers, computed entirely in SQL (verified
     * working identically on both SQLite — what the test suite runs on —
     * and Postgres, no PostGIS extension required). Scoped to ODPs owned by
     * the customer's own reseller (or the direct/no-reseller ODPs when the
     * customer has none), same tenant, with at least one available port —
     * reserved/used/damaged ports are never candidates.
     *
     * v0.16.0 Langkah 4 — signature/return type deliberately UNCHANGED
     * (every existing caller, WorkOrderService and the registration flow,
     * still gets exactly the same null/OdpPort back). The only addition:
     * OdpCapacityExhausted is dispatched when the query itself finds zero
     * available ports (every ODP in scope is genuinely full) — NOT when
     * null comes from the earlier "customer has no coordinates" guard
     * clause above, which isn't a capacity problem at all.
     */
    public function findNearestAvailable(Customer $customer): ?OdpPort
    {
        if ($customer->latitude === null || $customer->longitude === null) {
            return null;
        }

        $lat = (float) $customer->latitude;
        $lng = (float) $customer->longitude;

        $distanceExpr = '6371 * acos(cos(radians(?)) * cos(radians(odps.latitude)) * cos(radians(odps.longitude) - radians(?)) + sin(radians(?)) * sin(radians(odps.latitude)))';

        $port = OdpPort::query()
            ->join('odps', 'odps.id', '=', 'odp_ports.odp_id')
            ->where('odps.tenant_id', $customer->tenant_id)
            ->when(
                $customer->reseller_id !== null,
                fn ($query) => $query->where('odps.reseller_id', $customer->reseller_id),
                fn ($query) => $query->whereNull('odps.reseller_id'),
            )
            ->where('odp_ports.status', OdpPortStatus::Available->value)
            ->select('odp_ports.*')
            ->selectRaw("{$distanceExpr} as distance_km", [$lat, $lng, $lat])
            ->orderBy('distance_km')
            ->first();

        if ($port === null) {
            event(new OdpCapacityExhausted($customer));
        }

        return $port;
    }
}
