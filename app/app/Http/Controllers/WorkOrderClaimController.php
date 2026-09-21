<?php

namespace App\Http\Controllers;

use App\Enums\TechnicianStatus;
use App\Exceptions\WorkOrderAlreadyClaimedException;
use App\Models\Technician;
use App\Models\ToolType;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * v0.13.4.1 — Klaim WO via signed-link. Route SENGAJA di luar
 * 'admin.panel'/'auth:sanctum' — link ini genuinely public-facing, dibuka
 * teknisi dari pesan WhatsApp TANPA login sama sekali (lihat CLAUDE.md +
 * middleware 'signed' di routes/web.php). Identitas teknisi datang MURNI
 * dari parameter signed URL — tidak ada sesi untuk dibandingkan, jadi
 * "signature valid = otorisasi valid, titik." adalah desain yang disengaja,
 * bukan celah yang belum ditutup.
 *
 * Lookup manual (bukan implicit route-model-binding) — supaya 404 untuk
 * WO/teknisi yang tidak ada memberi pesan jelas, terpisah dari 403 generik
 * middleware 'signed' untuk signature kedaluwarsa/tidak valid.
 */
class WorkOrderClaimController extends Controller
{
    public function show(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimingTechnician] = $this->resolveOrAbort($work_order, $technician);

        if ($workOrder->claimed_at !== null) {
            return view('work-orders.claim', $this->alreadyClaimedViewData($workOrder, $claimingTechnician));
        }

        return view('work-orders.claim', [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            'partnerOptions' => $this->partnerOptions($workOrder, $claimingTechnician),
            'toolTypes' => ToolType::query()
                ->where('tenant_id', $workOrder->tenant_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'submitted' => false,
            'alreadyClaimed' => false,
        ]);
    }

    public function submit(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimingTechnician] = $this->resolveOrAbort($work_order, $technician);

        // Link lama (atau link baru dari reminder) yang masih valid tapi
        // WO-nya sudah diklaim orang lain lebih dulu — tampilkan read-only,
        // JANGAN proses form (data tidak pernah disentuh untuk kasus ini).
        if ($workOrder->claimed_at !== null) {
            return view('work-orders.claim', $this->alreadyClaimedViewData($workOrder, $claimingTechnician));
        }

        $partnerOptions = $this->partnerOptions($workOrder, $claimingTechnician);
        $toolTypes = ToolType::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $validated = $request->validate([
            'partner_ids' => ['array'],
            'partner_ids.*' => ['integer', 'distinct'],
            'tools' => ['array'],
            'tools.*.tool_type_id' => ['required_with:tools', 'integer'],
            'tools.*.quantity' => ['required_with:tools', 'integer', 'min:1'],
            'tools.*.technician_id' => ['required_with:tools', 'integer'],
            'modems' => ['array'],
            'modems.*.serial_number' => ['required_with:modems', 'string', 'max:255'],
            'modems.*.mac_address' => ['required_with:modems', 'string', 'max:255'],
            'modems.*.technician_id' => ['required_with:modems', 'integer'],
        ]);

        try {
            app(WorkOrderClaimService::class)->submit(
                $workOrder,
                $claimingTechnician,
                array_map('intval', $validated['partner_ids'] ?? []),
                $validated['tools'] ?? [],
                $validated['modems'] ?? [],
            );
        } catch (WorkOrderAlreadyClaimedException) {
            // Race condition: request lain berhasil klaim duluan di antara
            // GET form ditampilkan dan POST submit ini diproses — service
            // sudah menolaknya lewat row lock, tampilkan read-only, bukan
            // pesan error generik (data request ini tidak pernah tersimpan).
            return view('work-orders.claim', $this->alreadyClaimedViewData($workOrder, $claimingTechnician));
        } catch (InvalidArgumentException $e) {
            return view('work-orders.claim', [
                'workOrder' => $workOrder,
                'claimingTechnician' => $claimingTechnician,
                'partnerOptions' => $partnerOptions,
                'toolTypes' => $toolTypes,
                'submitted' => false,
                'alreadyClaimed' => false,
                'submitError' => $e->getMessage(),
            ]);
        }

        return view('work-orders.claim', [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            'partnerOptions' => $partnerOptions,
            'toolTypes' => $toolTypes,
            'submitted' => true,
            'alreadyClaimed' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyClaimedViewData(WorkOrder $workOrder, Technician $claimingTechnician): array
    {
        $workOrder->loadMissing([
            'claimedByTechnician',
            'claimPartners',
            'toolUsages.toolType',
            'toolUsages.technician',
            'modemUnits.technician',
        ]);

        return [
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            'submitted' => false,
            'alreadyClaimed' => true,
        ];
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

        return [$workOrder, $technician];
    }

    /**
     * Daftar teknisi Active se-tenant, di luar teknisi utama sendiri — untuk
     * dropdown multi-select "Partner Kerja".
     *
     * @return Collection<int, Technician>
     */
    private function partnerOptions(WorkOrder $workOrder, Technician $claimingTechnician)
    {
        return Technician::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('status', TechnicianStatus::Active)
            ->where('id', '!=', $claimingTechnician->id)
            ->orderBy('name')
            ->get();
    }
}
