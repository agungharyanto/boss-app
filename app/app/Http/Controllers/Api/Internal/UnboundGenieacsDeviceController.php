<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\CpeDevice;
use App\Services\Network\UnboundGenieacsDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/internal/cpe-devices/unbound-genieacs — daftar device yang ADA
 * di GenieACS tapi belum punya baris cpe_devices (section "Belum Ter-bind"
 * di /cpe-devices). Client-side DataTables + auto-reload (ajax.reload),
 * sama pola tabel utama; deliberately di routes/web.php (session auth,
 * bukan Sanctum) seperti endpoint internal cpe-devices lainnya.
 *
 * Admin-only lewat CpeDevicePolicy::viewUnbound (cpe_devices.view/.manage)
 * — triage device belum-ter-bind adalah operasi NOC/ISP-admin, bukan
 * tugas reseller. Reseller user tetap bisa membuka /cpe-devices tapi
 * section ini tidak dirender untuk mereka (blade @can), dan endpoint ini
 * menolak mereka 403, sama posture /cpe-devices/status-check.
 *
 * genieacs-nbi tak terjangkau -> HTTP 200 dengan data kosong + pesan
 * `error` (bukan 500) supaya auto-reload tidak "meledak" di layar; error
 * asli tetap masuk log.
 */
class UnboundGenieacsDeviceController extends Controller
{
    public function __invoke(UnboundGenieacsDeviceService $service): JsonResponse
    {
        $this->authorize('viewUnbound', CpeDevice::class);

        try {
            return response()->json(['data' => $service->list()]);
        } catch (Throwable $e) {
            Log::warning('UnboundGenieacsDeviceController: gagal memuat daftar device belum ter-bind — '.$e->getMessage());

            return response()->json([
                'data' => [],
                'error' => 'GenieACS tidak terjangkau saat memuat daftar device belum ter-bind.',
            ]);
        }
    }
}
