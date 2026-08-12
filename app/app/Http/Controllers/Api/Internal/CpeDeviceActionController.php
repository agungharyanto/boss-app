<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\RebootCpeDeviceRequest;
use App\Http\Requests\ReplaceCpeModemRequest;
use App\Http\Requests\SetCpeWifiCredentialsRequest;
use App\Http\Resources\CpeActionLogResource;
use App\Models\CpeDevice;
use App\Services\Network\CpeActionService;
use App\Services\Network\CpeBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Session-authenticated (routes/web.php) counterpart to
 * App\Http\Controllers\Api\V1\CpeDeviceController's reboot()/setWifi() —
 * the /cpe-devices DataTables child row (v0.7.6-follow-up) calls these
 * instead of the v1 endpoints because v1 sits behind `auth:sanctum`, which
 * only accepts a real API token or a properly configured Sanctum SPA
 * "stateful" session (neither of which exists in this project — confirmed
 * empirically, a plain session-cookie request to /api/v1/... redirects to
 * /login instead of authenticating). Reuses the exact same Form Requests
 * (RebootCpeDeviceRequest, SetCpeWifiCredentialsRequest — both already
 * gate on `$this->route('cpe_device')`, which works identically here since
 * this controller's routes also bind the parameter as {cpe_device}) and
 * the exact same CpeActionService/CpeBindingService methods — no business
 * logic is duplicated, only the auth boundary differs.
 */
class CpeDeviceActionController extends Controller
{
    use ApiResponds;

    public function reboot(RebootCpeDeviceRequest $request, CpeDevice $cpeDevice, CpeActionService $service): JsonResponse
    {
        $log = $service->reboot($cpeDevice, $request->user());

        return $this->success(
            new CpeActionLogResource($log),
            $log->status->value === 'delivered'
                ? 'Perintah reboot terkirim — akan diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil). Ini BUKAN konfirmasi perangkat sudah menjalankannya.'
                : 'Perintah reboot GAGAL dikirim: '.$log->failed_reason
        );
    }

    public function wifi(SetCpeWifiCredentialsRequest $request, CpeDevice $cpeDevice, CpeActionService $service): JsonResponse
    {
        $log = $service->setWifiCredentials(
            $cpeDevice,
            $request->validated('ssid'),
            $request->validated('password'),
            $request->user(),
        );

        return $this->success(
            new CpeActionLogResource($log),
            $log->status->value === 'delivered'
                ? 'Perintah ganti WiFi terkirim — akan diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil). Ini BUKAN konfirmasi perangkat sudah menjalankannya.'
                : 'Perintah ganti WiFi GAGAL dikirim: '.$log->failed_reason
        );
    }

    /**
     * "Remove" — unbinds a wrongly-matched device from its customer, see
     * CpeBindingService::unbindWithRejection()'s own docblock for why this
     * also writes a cpe_binding_rejections row (never re-created by
     * App\Services\Network\LegacyDeviceMatcherService's scheduled run).
     */
    public function destroy(Request $request, CpeDevice $cpeDevice, CpeBindingService $service): JsonResponse
    {
        $this->authorize('manage', $cpeDevice);

        $service->unbindWithRejection($cpeDevice, $request->user()->id);

        return $this->success(null, 'Device berhasil di-unbind dari pelanggan. Pasangan ini tidak akan di-match otomatis lagi.');
    }

    /**
     * "Ganti Modem" — deliberate hardware swap, see
     * CpeBindingService::replaceModem()'s own docblock for why this does
     * NOT write a cpe_binding_rejections row (unlike destroy() above).
     */
    public function replaceModem(ReplaceCpeModemRequest $request, CpeDevice $cpeDevice, CpeBindingService $service): JsonResponse
    {
        $newDevice = $service->replaceModem($cpeDevice, $request->validated('serial_number'));

        return $this->success(
            ['id' => $newDevice->id],
            'Modem berhasil diganti. Kalau device baru belum pernah dikenal GenieACS, statusnya akan "Menunggu Koneksi Pertama" sampai dia inform pertama kali.'
        );
    }
}
