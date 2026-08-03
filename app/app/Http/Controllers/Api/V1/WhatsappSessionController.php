<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappSessionService;
use Illuminate\Http\JsonResponse;

class WhatsappSessionController extends Controller
{
    use ApiResponds;

    /**
     * BelongsToResellerScope narrows this automatically for a resolved
     * reseller context; an ISP admin (no context) sees every session
     * including "direct".
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', WhatsappSession::class);

        $sessions = WhatsappSession::with('reseller')->get();

        return $this->success($sessions, 'Daftar sesi WhatsApp');
    }

    public function show(WhatsappSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        return $this->success($session->load('reseller'), 'Detail sesi WhatsApp');
    }

    public function refreshQr(WhatsappSession $session, WhatsappSessionService $service): JsonResponse
    {
        $this->authorize('manage', $session);

        $qrCodeData = $service->refreshQrCode($session);

        return $this->success(['qr_code_data' => $qrCodeData], 'QR code diperbarui');
    }
}
