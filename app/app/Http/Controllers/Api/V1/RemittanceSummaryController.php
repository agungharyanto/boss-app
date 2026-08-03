<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateRemittanceSummaryRequest;
use App\Http\Resources\KomdigiRemittanceSummaryResource;
use App\Models\KomdigiRemittanceSummary;
use App\Services\Tax\RemittanceSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin-only — same reasoning as TaxLedgerController.
 */
class RemittanceSummaryController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('remittance_summary.view');

        $summaries = KomdigiRemittanceSummary::query()
            ->with(['reseller', 'taxComponent'])
            ->when($request->filled('reseller_id'), fn ($q) => $q->where('reseller_id', $request->integer('reseller_id')))
            ->when($request->filled('period_start'), fn ($q) => $q->whereDate('period_start', $request->date('period_start')->toDateString()))
            ->when($request->filled('period_end'), fn ($q) => $q->whereDate('period_end', $request->date('period_end')->toDateString()))
            ->latest('period_start')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            KomdigiRemittanceSummaryResource::collection($summaries->items()),
            'Daftar remittance summary',
            ['pagination' => [
                'current_page' => $summaries->currentPage(),
                'per_page' => $summaries->perPage(),
                'total' => $summaries->total(),
                'last_page' => $summaries->lastPage(),
            ]]
        );
    }

    public function generate(GenerateRemittanceSummaryRequest $request, RemittanceSummaryService $service): JsonResponse
    {
        $service->generateForPeriod(
            Carbon::parse($request->validated('period_start')),
            Carbon::parse($request->validated('period_end'))
        );

        return $this->success(null, 'Remittance summary berhasil digenerate untuk periode tersebut');
    }
}
