<?php

namespace App\Services\Installation;

use App\Enums\OdpPortStatus;
use App\Events\OdpCapacityExhausted;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;

class OdpLocatorService
{
    /**
     * Haversine great-circle distance in km, `?`-parametrised as
     * (lat, lng, lat). Same expression findNearestAvailable() has used
     * since v0.16.0 Langkah 4 — extracted so nearestCandidates() (Langkah
     * 11) reuses the exact same maths, verified identical on SQLite and
     * Postgres, no PostGIS.
     */
    private const DISTANCE_KM_EXPR = '6371 * acos(cos(radians(?)) * cos(radians(odps.latitude)) * cos(radians(odps.longitude) - radians(?)) + sin(radians(?)) * sin(radians(odps.latitude)))';

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

        $distanceExpr = self::DISTANCE_KM_EXPR;

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

    /**
     * v0.16.0 Langkah 11 — the N nearest ODPs to a raw point, for the
     * "Cek Jalur ke ODP" sales feature. Unlike findNearestAvailable():
     *  - takes raw lat/lng (a sales prospect isn't a Customer row yet),
     *    not a Customer model;
     *  - returns SEVERAL candidates so the rep can pick the right one
     *    when a few ODPs sit close together;
     *  - does NOT filter to "has an available port" — a full ODP is still
     *    shown (with its capacity) so the rep sees the whole picture;
     *  - never dispatches OdpCapacityExhausted (not a provisioning path).
     *
     * Tenant/reseller scoping comes from Odp's own global scopes (the
     * acting Livewire request's user), same as FiberTopologyService's
     * topologyMapMarkers()/topologyMapCustomers().
     *
     * @return list<array{odp_id: int, code: string, name: string, latitude: float, longitude: float, distance_km: float, used_ports: int, total_ports: int}>
     */
    public function nearestCandidates(float $lat, float $lng, int $limit = 5): array
    {
        return Odp::query()
            ->whereNotNull('odps.latitude')
            ->whereNotNull('odps.longitude')
            ->select('odps.*')
            ->withCount(['ports as used_ports_count' => fn ($q) => $q->where('status', OdpPortStatus::Used->value)])
            ->selectRaw(self::DISTANCE_KM_EXPR.' as distance_km', [$lat, $lng, $lat])
            ->orderBy('distance_km')
            ->limit($limit)
            ->get()
            ->map(fn (Odp $odp) => [
                'odp_id' => $odp->id,
                'code' => $odp->code,
                'name' => $odp->name,
                'latitude' => (float) $odp->latitude,
                'longitude' => (float) $odp->longitude,
                'distance_km' => round((float) $odp->distance_km, 3),
                'used_ports' => (int) $odp->used_ports_count,
                'total_ports' => (int) $odp->total_ports,
            ])
            ->all();
    }
}
