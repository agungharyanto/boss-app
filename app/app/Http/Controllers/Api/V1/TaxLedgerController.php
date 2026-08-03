<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResellerTaxLedgerResource;
use App\Models\ResellerTaxLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only, read-only — regulatory ledger detail isn't exposed to
 * resellers in v0.3.3 (not requested in scope; only aggregate figures via
 * komdigi_remittance_summary would be a candidate for reseller visibility,
 * and that's also admin-only for now).
 */
class TaxLedgerController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('tax_ledger.view');

        $entries = ResellerTaxLedger::query()
            ->with(['reseller', 'taxComponent'])
            ->when($request->filled('reseller_id'), fn ($q) => $q->where('reseller_id', $request->integer('reseller_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('date_from')->toDateString()))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('date_to')->toDateString()))
            ->latest('transaction_date')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ResellerTaxLedgerResource::collection($entries->items()),
            'Daftar tax ledger',
            ['pagination' => [
                'current_page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
            ]]
        );
    }
}
