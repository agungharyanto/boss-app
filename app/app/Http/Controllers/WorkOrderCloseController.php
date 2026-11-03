<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderDeviceType;
use App\Enums\WorkOrderPhotoType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\WorkOrderCloseException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Models\WorkOrderDevice;
use App\Services\Installation\WorkOrderCloseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * v0.13.4.3.1 — Tutup WO via signed-link (tahap-3 setelah klaim+aktivasi).
 * Route public-facing TANPA login (middleware 'signed'+'throttle:20,1' di
 * routes/web.php) — signature = otorisasi, HANYA teknisi PENGKLAIM. Gerbang
 * keras ONU online + WiFi terkirim + guard nama + tanda tangan ada di
 * WorkOrderCloseService (BOSS-005); controller tipis.
 */
class WorkOrderCloseController extends Controller
{
    public function show(Request $request, int $work_order, int $technician): View|RedirectResponse
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        if ($workOrder->status === WorkOrderStatus::Completed) {
            return $this->statusView($workOrder, $claimer);
        }

        return view('work-orders.close', $this->formViewData($workOrder, $claimer));
    }

    public function submit(Request $request, int $work_order, int $technician): View|RedirectResponse
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        // Idempoten: sudah ditutup -> halaman status, bukan proses ulang/error.
        if ($workOrder->status === WorkOrderStatus::Completed) {
            return $this->statusView($workOrder, $claimer);
        }

        // v0.13.4.3.2 — hanya JPG/PNG (WebP di-drop: gagal di whitelist media
        // WA saat dikirim ke grup "WO selesai"). max 10 MB tetap.
        $photoRules = [];
        $photoMessages = [];
        foreach (WorkOrderPhotoType::cases() as $type) {
            $key = "photos.{$type->value}";
            $photoRules[$key] = ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'];
            $photoMessages["{$key}.mimes"] = 'Foto :attribute harus JPG atau PNG (WebP tidak didukung).';
            $photoMessages["{$key}.max"] = 'Foto :attribute maksimal 10 MB.';
            $photoMessages["{$key}.image"] = 'File :attribute harus berupa gambar JPG/PNG.';
            $photoMessages["{$key}.required"] = 'Foto '.$type->label().' wajib diunggah.';
        }

        $validated = $request->validate(array_merge([
            'customer_name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string'],
        ], $photoRules), $photoMessages);

        try {
            app(WorkOrderCloseService::class)->closeFromForm(
                $workOrder,
                $claimer,
                $validated['customer_name'],
                $request->file('photos', []),
                $validated['signature'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidWorkOrderStatusTransitionException) {
            return $this->statusView($workOrder->fresh(), $claimer);
        } catch (WorkOrderCloseException $e) {
            return view('work-orders.close', $this->formViewData($workOrder, $claimer, $e->getMessage(), $validated['customer_name']));
        }

        return redirect()->to($this->signedStatusUrl($workOrder, $claimer));
    }

    public function status(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        return $this->statusView($workOrder, $claimer);
    }

    private function statusView(WorkOrder $workOrder, Technician $claimer): View
    {
        return view('work-orders.close-status', [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimer,
            'completed' => $workOrder->status === WorkOrderStatus::Completed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(WorkOrder $workOrder, Technician $claimer, ?string $submitError = null, ?string $oldName = null): array
    {
        $customer = Customer::withoutGlobalScopes()->find($workOrder->customer_id);
        $closeService = app(WorkOrderCloseService::class);
        $gate = $closeService->gateState($workOrder);
        // v0.19.1 wiring: teks persyaratan DINAMIS ter-render (sama dengan
        // yang akan di-snapshot saat tanda tangan).
        $rendered = $closeService->renderedTerms($workOrder);

        $ont = WorkOrderDevice::where('work_order_id', $workOrder->id)
            ->where('device_type', WorkOrderDeviceType::Ont)
            ->orderBy('id')
            ->first();

        return [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimer,
            'customer' => $customer,
            'gate' => $gate,
            'ssid' => $ont?->ssid,
            'termsText' => $rendered['text'],
            'termsVersion' => $rendered['version'],
            'photoTypes' => WorkOrderPhotoType::cases(),
            'submitError' => $submitError,
            'oldName' => $oldName,
            'statusUrl' => $this->signedStatusUrl($workOrder, $claimer),
        ];
    }

    private function signedStatusUrl(WorkOrder $workOrder, Technician $claimer): string
    {
        return URL::temporarySignedRoute(
            'work-orders.close.status',
            now()->addDays(2),
            ['work_order' => $workOrder->id, 'technician' => $claimer->id],
        );
    }

    /**
     * @return array{0: WorkOrder, 1: Technician}
     */
    private function resolveOrAbort(int $workOrderId, int $technicianId): array
    {
        $workOrder = WorkOrder::withoutGlobalScopes()->find($workOrderId);
        abort_if($workOrder === null, 404, 'Work Order tidak ditemukan.');

        $technician = Technician::withoutGlobalScopes()
            ->where('id', $technicianId)
            ->where('tenant_id', $workOrder->tenant_id)
            ->first();
        abort_if($technician === null, 404, 'Teknisi tidak ditemukan untuk Work Order ini.');

        abort_if($workOrder->claimed_at === null, 403, 'Work Order ini belum diklaim.');
        abort_if(
            (int) $workOrder->claimed_by_technician_id !== (int) $technician->id,
            403,
            'Penutupan hanya boleh dilakukan oleh teknisi yang mengklaim Work Order ini.'
        );

        return [$workOrder, $technician];
    }
}
