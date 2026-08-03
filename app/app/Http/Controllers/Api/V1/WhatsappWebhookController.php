<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\Whatsapp\WhatsappSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public endpoint (no Sanctum — the Node whatsapp-gateway service can't log
 * in as a BOSS App user) — HMAC-SHA256 signature verification inside
 * WhatsappSessionService::updateStatusFromWebhook stands in for auth
 * entirely, same posture as XenditWebhookController. Always responds HTTP
 * 200 regardless of outcome, same "don't make the sender retry forever"
 * reasoning.
 */
class WhatsappWebhookController extends Controller
{
    use ApiResponds;

    public function sessionStatus(Request $request, WhatsappSessionService $service): JsonResponse
    {
        try {
            $applied = $service->updateStatusFromWebhook(
                $request->getContent(),
                $request->header('X-Whatsapp-Signature'),
                $request->header('X-Whatsapp-Timestamp'),
                $request->all(),
            );
        } catch (Throwable $e) {
            Log::error('Whatsapp session-status webhook threw an unexpected exception', [
                'message' => $e->getMessage(),
            ]);

            return $this->success(['result' => 'error_logged'], 'Webhook diterima');
        }

        return $this->success(['result' => $applied ? 'applied' : 'rejected'], 'Webhook diterima');
    }
}
