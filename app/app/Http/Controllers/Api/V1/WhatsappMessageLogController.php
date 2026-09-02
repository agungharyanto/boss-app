<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageLog;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappMessageLogController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this to the reseller's own queue only;
     * an ISP admin sees every reseller's + the direct session's logs,
     * optionally filtered by ?reseller_id=.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WhatsappMessageLog::class);

        $logs = WhatsappMessageLog::query()
            ->knownEventType()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('reseller_id'), fn ($q) => $q->where('reseller_id', $request->integer('reseller_id')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->success($logs->items(), 'Daftar antrian pesan WhatsApp', [
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function retry(WhatsappMessageLog $log, WhatsappGatewayService $service): JsonResponse
    {
        $this->authorize('retry', $log);

        $service->retry($log);

        return $this->success($log->fresh(), 'Pesan diantrikan ulang');
    }
}
