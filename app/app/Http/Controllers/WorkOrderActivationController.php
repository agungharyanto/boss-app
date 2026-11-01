<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\WorkOrderActivationException;
use App\Models\Customer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * v0.13.4.2.2 — Aktivasi pelanggan via signed-link (tahap-2 setelah klaim).
 * Route SENGAJA di luar 'admin.panel'/'auth' — public-facing, dibuka teknisi
 * dari WhatsApp TANPA login; `signature` = otorisasi (middleware 'signed' +
 * 'throttle:20,1' di routes/web.php). HANYA teknisi PENGKLAIM
 * (`technician` di link == `claimed_by_technician_id`) yang boleh; selain itu
 * 403 jelas. Logika aktivasi ada di WorkOrderActivationService (BOSS-005);
 * controller ini tipis.
 */
class WorkOrderActivationController extends Controller
{
    public function show(Request $request, int $work_order, int $technician): View|RedirectResponse
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        if ($workOrder->status === WorkOrderStatus::InProgress) {
            return $this->statusView($workOrder, $claimer);
        }

        return view('work-orders.activate', $this->formViewData($workOrder, $claimer));
    }

    public function submit(Request $request, int $work_order, int $technician): View|RedirectResponse
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        // Idempoten: sudah diaktivasi (InProgress) -> tampilkan status, bukan
        // memproses ulang / error.
        if ($workOrder->status === WorkOrderStatus::InProgress) {
            return $this->statusView($workOrder, $claimer);
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'ssid' => ['required', 'string', 'max:32'],
            'wifi_password' => ['required', 'string', 'min:8', 'max:63'],
        ]);

        try {
            app(WorkOrderActivationService::class)->activateFromForm(
                $workOrder,
                $claimer,
                $validated['customer_name'],
                $validated['ssid'],
                $validated['wifi_password'],
            );
        } catch (InvalidWorkOrderStatusTransitionException) {
            // Race: aktivasi lain memindahkan WO ke InProgress di antara cek di
            // atas dan prepare() -> tampilkan status, bukan error mentah.
            return $this->statusView($workOrder->fresh(), $claimer);
        } catch (WorkOrderActivationException $e) {
            return view('work-orders.activate', $this->formViewData($workOrder, $claimer, $e->getMessage(), $validated['ssid']));
        }

        // Sukses -> redirect ke halaman status (signed, agar lolos middleware).
        return redirect()->to($this->signedStatusUrl($workOrder, $claimer));
    }

    public function status(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimer] = $this->resolveOrAbort($work_order, $technician);

        return $this->statusView($workOrder, $claimer);
    }

    private function statusView(WorkOrder $workOrder, Technician $claimer): View
    {
        // v0.13.4.3.1 — tombol "Tutup Work Order" muncul di halaman status saat
        // WO sudah InProgress (teraktivasi). Signed-link tutup WO (2 hari, pola
        // sama), hanya untuk teknisi pengklaim.
        $closeUrl = $workOrder->status === WorkOrderStatus::InProgress
            ? URL::temporarySignedRoute(
                'work-orders.close.show',
                now()->addDays(2),
                ['work_order' => $workOrder->id, 'technician' => $claimer->id],
            )
            : null;

        return view('work-orders.activate-status', [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimer,
            'activation' => app(WorkOrderActivationService::class)->statusFor($workOrder),
            'closeUrl' => $closeUrl,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(WorkOrder $workOrder, Technician $claimer, ?string $submitError = null, ?string $ssidOld = null): array
    {
        $customer = Customer::withoutGlobalScopes()->find($workOrder->customer_id);
        $units = $workOrder->modemUnits()->orderBy('id')->get();

        return [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimer,
            'customer' => $customer,
            'pppoeUsername' => $customer?->cid ? "{$customer->cid}@ppp.bajastu.id" : null,
            'firstModem' => $units->first(),
            'modemUnitCount' => $units->count(),
            'ssidSuggestion' => $ssidOld ?? ($customer?->name ? substr(trim($customer->name), 0, 32) : ''),
            'submitError' => $submitError,
        ];
    }

    private function signedStatusUrl(WorkOrder $workOrder, Technician $claimer): string
    {
        return URL::temporarySignedRoute(
            'work-orders.activate.status',
            now()->addDays(2),
            ['work_order' => $workOrder->id, 'technician' => $claimer->id],
        );
    }

    /**
     * Resolve WO + teknisi; abort jelas kalau tidak ada, belum diklaim, atau
     * teknisi di link BUKAN pengklaim.
     *
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

        abort_if($workOrder->claimed_at === null, 403, 'Work Order ini belum diklaim — aktivasi hanya setelah klaim.');
        abort_if(
            (int) $workOrder->claimed_by_technician_id !== (int) $technician->id,
            403,
            'Aktivasi hanya boleh dilakukan oleh teknisi yang mengklaim Work Order ini.'
        );

        return [$workOrder, $technician];
    }
}
