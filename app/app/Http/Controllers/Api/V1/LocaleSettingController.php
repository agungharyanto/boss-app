<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLocaleSettingRequest;
use App\Http\Resources\LocaleSettingResource;
use App\Services\LocaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocaleSettingController extends Controller
{
    use ApiResponds;

    public function show(Request $request, LocaleService $service): JsonResponse
    {
        return $this->success(new LocaleSettingResource([
            'locale' => $service->get($request->user()),
            'supported' => $service->supported(),
        ]));
    }

    public function update(UpdateLocaleSettingRequest $request, LocaleService $service): JsonResponse
    {
        $service->update($request->user(), $request->string('locale')->toString());

        return $this->success(new LocaleSettingResource([
            'locale' => $service->get($request->user()),
            'supported' => $service->supported(),
        ]), 'Bahasa berhasil disimpan');
    }
}
