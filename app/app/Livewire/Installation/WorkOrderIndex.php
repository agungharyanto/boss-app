<?php

namespace App\Livewire\Installation;

use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Policies\WorkOrderPolicy;
use App\Services\Installation\WorkOrderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.26.2b — "Pelanggan > Work Order". Daftar SAJA (bukan CRUD penuh) —
 * WO tetap cuma lahir dari `WorkOrderService::createFromSubscription()`
 * (sekarang dipanggil `SubscriptionIndex::createSubscription()`, lihat
 * docblock-nya). Satu-satunya aksi tulis di sini: assign teknisi inline,
 * REUSE `WorkOrderService::assignTechnician()` + otorisasi Policy yang
 * SAMA persis dengan `AssignWorkOrderRequest` (`can('manage', $workOrder)`)
 * — tidak ada logic validasi/otorisasi baru ditulis di sini.
 *
 * Visibility list mengikuti `WorkOrderPolicy` PERSIS seperti
 * `WorkOrderController::index()` (reseller di-scope otomatis lewat
 * `BelongsToResellerScope` pada `WorkOrder`, teknisi-only user di-scope
 * lewat `WorkOrderPolicy::scopeForTechnician()`) — satu definisi
 * visibility, tidak didrift ulang di sini.
 */
class WorkOrderIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const WITH = ['customer', 'subscription', 'technician'];

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkOrder::class);
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * `Technician::assignTechnician()` mentransisikan status ke
     * `Assigned` — HANYA valid dari status `Ready` (state machine
     * `WorkOrderStatus::canTransitionTo()`). Gagal transisi (WO belum
     * `Ready`) ditangkap dan ditampilkan sebagai error, BUKAN dibiarkan
     * jadi exception mentah — dropdown di view sendiri juga cuma
     * ditampilkan untuk baris berstatus `Ready`, ini pertahanan lapis
     * kedua (payload Livewire bisa dimanipulasi klien).
     */
    public function assignTechnician(int $workOrderId, string $technicianId, WorkOrderService $service): void
    {
        // Opsi "Belum ditugaskan" (value="") dipilih ulang — no-op, bukan
        // unassign (tidak ada method unassign yang di-reuse di sini,
        // scope v0.26.2b cuma assign inline).
        if ($technicianId === '') {
            return;
        }

        $workOrder = WorkOrder::findOrFail($workOrderId);
        $this->authorize('manage', $workOrder);

        $technician = Technician::where('tenant_id', $workOrder->tenant_id)->findOrFail((int) $technicianId);

        try {
            $service->assignTechnician($workOrder, $technician);
        } catch (InvalidWorkOrderStatusTransitionException $e) {
            $this->addError('assign', $e->getMessage());
        }
    }

    public function render(WorkOrderPolicy $policy)
    {
        $user = auth()->user();

        $workOrders = WorkOrder::query()
            ->with(self::WITH)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($policy->isTechnicianOnly($user), fn ($q) => $policy->scopeForTechnician($user, $q))
            ->latest()
            ->paginate(15);

        return view('livewire.installation.work-order-index', [
            'workOrders' => $workOrders,
            'technicians' => Technician::active()->orderBy('name')->get(),
            'statuses' => WorkOrderStatus::cases(),
        ]);
    }
}
