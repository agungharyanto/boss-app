<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateThemeSettingsRequest;
use App\Http\Resources\ThemeSettingsResource;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeSettingsController extends Controller
{
    use ApiResponds;

    public function show(Request $request, ThemeSettingsService $service): JsonResponse
    {
        return $this->success(new ThemeSettingsResource($service->get($request->user())));
    }

    public function update(UpdateThemeSettingsRequest $request, ThemeSettingsService $service): JsonResponse
    {
        $service->update($request->user(), $request->string('primary_color')->toString(), $request->string('text_color')->toString());

        return $this->success(new ThemeSettingsResource($service->get($request->user())), 'Tema berhasil disimpan');
    }
}
