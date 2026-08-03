<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDashboardWidgetsRequest;
use App\Http\Resources\DashboardWidgetsResource;
use App\Services\DashboardWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardWidgetSettingController extends Controller
{
    use ApiResponds;

    public function show(Request $request, DashboardWidgetService $service): JsonResponse
    {
        return $this->success(new DashboardWidgetsResource([
            'active' => $service->activeWidgetValues($request->user()),
        ]));
    }

    public function update(UpdateDashboardWidgetsRequest $request, DashboardWidgetService $service): JsonResponse
    {
        $service->update($request->user(), $request->input('widgets'));

        return $this->success(new DashboardWidgetsResource([
            'active' => $service->activeWidgetValues($request->user()),
        ]), 'Widget dashboard berhasil disimpan');
    }
}
