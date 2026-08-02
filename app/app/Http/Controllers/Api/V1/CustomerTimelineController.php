<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTimelineEntryResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerTimelineController extends Controller
{
    use ApiResponds;

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('customer_timeline.view');

        $entries = $customer->timelineEntries()
            ->with('actor')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            CustomerTimelineEntryResource::collection($entries->items()),
            'Timeline pelanggan',
            [
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'per_page' => $entries->perPage(),
                    'total' => $entries->total(),
                    'last_page' => $entries->lastPage(),
                ],
            ]
        );
    }
}
