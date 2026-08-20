<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\OltDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * v0.8.1 — server-side DataTables list for /olt-devices, same yajra
 * pattern as CpeDeviceDatatableController (v0.7.6-follow-up). Reseller
 * scoping is automatic via BelongsToResellerScope on OltDevice, no manual
 * filter needed here.
 */
class OltDeviceDatatableController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OltDevice::class);

        $query = OltDevice::query()->with(['nas:id,name', 'oltModel.manufacturer', 'reseller:id,name']);

        return DataTables::eloquent($query)
            ->addColumn('nas_name', fn (OltDevice $d) => $d->nas?->name)
            ->addColumn('manufacturer_name', fn (OltDevice $d) => $d->oltModel?->manufacturer?->name)
            ->addColumn('model_name', fn (OltDevice $d) => $d->oltModel?->name)
            ->addColumn('reseller_name', fn (OltDevice $d) => $d->reseller?->name)
            ->addColumn('protocol_label', fn (OltDevice $d) => $d->access_protocol->label())
            ->addColumn('test_result_label', fn (OltDevice $d) => $d->last_connection_test_result?->label() ?? 'Belum pernah dites')
            ->addColumn('test_result_value', fn (OltDevice $d) => $d->last_connection_test_result?->value)
            ->addColumn('last_connection_test_at_human', fn (OltDevice $d) => $d->last_connection_test_at?->diffForHumans() ?? '-')
            ->filter(function ($query) use ($request) {
                $keyword = (string) ($request->input('search.value') ?? '');

                if ($keyword === '') {
                    return;
                }

                $needle = '%'.mb_strtolower($keyword).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(ip_address) LIKE ?', [$needle])
                    ->orWhereHas('nas', fn ($q2) => $q2->whereRaw('LOWER(name) LIKE ?', [$needle])));
            })
            // Explicit whitelist — same reasoning as CpeDeviceDatatableController:
            // without this, yajra would serialize the full model (including
            // decrypted credential columns) into the JSON response.
            ->only([
                'id', 'name', 'nas_name', 'ip_address', 'manufacturer_name', 'model_name',
                'reseller_name', 'protocol_label', 'test_result_label', 'test_result_value',
                'last_connection_test_at_human',
            ])
            ->make(true);
    }
}
