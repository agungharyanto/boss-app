<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public endpoint (no Sanctum — Xendit can't log in) — signature
 * verification via `x-callback-token` inside PaymentService::handleWebhook
 * stands in for authentication entirely. Always responds HTTP 200
 * regardless of the internal WebhookProcessingResult (applied, duplicate,
 * rejected-whatever) — a non-200 makes Xendit retry the same webhook
 * repeatedly, which is never what we want here; the actual outcome is only
 * ever visible via payment_webhook_logs / the reconciliation report.
 */
class XenditWebhookController extends Controller
{
    use ApiResponds;

    public function handle(Request $request, PaymentService $service): JsonResponse
    {
        try {
            $result = $service->handleWebhook($request->all(), $request->header('x-callback-token'));
        } catch (Throwable $e) {
            // Never let an unexpected error surface as a non-200 here —
            // log it and still acknowledge receipt, same reasoning as the
            // rejection paths above.
            Log::error('Xendit webhook processing threw an unexpected exception', [
                'message' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return $this->success(['result' => 'error_logged'], 'Webhook diterima');
        }

        return $this->success(['result' => $result->value], 'Webhook diterima');
    }
}
