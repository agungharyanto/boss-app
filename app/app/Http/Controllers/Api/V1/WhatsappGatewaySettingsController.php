<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WhatsappGatewaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappGatewaySettingsController extends Controller
{
    use ApiResponds;

    public function show(): JsonResponse
    {
        $this->authorize('view', WhatsappGatewaySettings::class);

        return $this->success(WhatsappGatewaySettings::current(), 'Pengaturan WhatsApp Gateway');
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('manage', WhatsappGatewaySettings::class);

        $data = $request->validate([
            'rate_limit_delay_min_seconds' => ['required', 'integer', 'min:1'],
            'rate_limit_delay_max_seconds' => ['required', 'integer', 'gte:rate_limit_delay_min_seconds'],
            'rate_limit_batch_size' => ['required', 'integer', 'min:1'],
            'rate_limit_batch_pause_min_minutes' => ['required', 'integer', 'min:1'],
            'rate_limit_batch_pause_max_minutes' => ['required', 'integer', 'gte:rate_limit_batch_pause_min_minutes'],
            'daily_schedule_times' => ['required', 'array', 'min:1'],
            'daily_schedule_times.*' => ['string', 'date_format:H:i'],
        ]);

        $settings = WhatsappGatewaySettings::current();
        $settings->update($data);

        return $this->success($settings->fresh(), 'Pengaturan disimpan');
    }
}
