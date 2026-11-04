<?php

namespace App\Http\Controllers;

use App\Enums\TechnicianStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderAlreadyClaimedException;
use App\Exceptions\WorkOrderNotClaimableException;
use App\Http\Controllers\Concerns\BuildsWorkOrderStatusCards;
use App\Models\Technician;
use App\Models\ToolType;
use App\Models\WorkOrder;
use App\Services\Installation\WorkOrderClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
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
 *
 * v0.13.4.5 — halaman sadar status: WO terminal (SELESAI/DIBATALKAN) atau
 * sudah diambil teknisi lain menampilkan KARTU status (bukan form); WO yang
 * sudah diklaim teknisi INI menampilkan identitas + tombol lanjut aktivasi.
 */
class WorkOrderClaimController extends Controller
{
    use BuildsWorkOrderStatusCards;

    private const PAGE_TITLE = 'Klaim Work Order';

    public function show(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimingTechnician] = $this->resolveOrAbort($work_order, $technician);

        if ($card = $this->statusCardForClaim($workOrder, $claimingTechnician)) {
            return $card;
        }

        if ($workOrder->claimed_at !== null) {
            // Sudah diklaim oleh teknisi INI (claimed-by-other sudah ditangani
            // di statusCardForClaim di atas) — tampilkan identitas + tombol
            // lanjut aktivasi, bukan form kosong lagi.
            return view('work-orders.claim', $this->claimedByMeViewData($workOrder, $claimingTechnician));
        }

        return view('work-orders.claim', $this->availableViewData($workOrder, $claimingTechnician));
    }

    public function submit(Request $request, int $work_order, int $technician): View
    {
        [$workOrder, $claimingTechnician] = $this->resolveOrAbort($work_order, $technician);

        // Defense in depth: WO terminal / diambil orang lain tidak pernah
        // diproses — tampilkan kartu status, bukan menyentuh data.
        if ($card = $this->statusCardForClaim($workOrder, $claimingTechnician)) {
            return $card;
        }

        if ($workOrder->claimed_at !== null) {
            // Sudah diklaim teknisi INI (mis. buka ulang/double submit) —
            // read-only identitas + aktivasi, bukan proses ulang.
            return view('work-orders.claim', $this->claimedByMeViewData($workOrder, $claimingTechnician));
        }

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
        } catch (WorkOrderNotClaimableException) {
            // Race: WO jadi terminal (Completed/Cancelled) antara GET dan POST.
            $workOrder->refresh();

            return $workOrder->status === WorkOrderStatus::Cancelled
                ? $this->cancelledCard($workOrder, self::PAGE_TITLE)
                : $this->completedCard($workOrder, self::PAGE_TITLE);
        } catch (WorkOrderAlreadyClaimedException) {
            // Race: teknisi lain berhasil klaim duluan di antara GET form dan
            // POST ini — tampilkan kartu "sudah diambil", data tidak pernah
            // tersimpan (row lock di service menolaknya).
            return $this->claimedByOtherCard($workOrder->refresh(), self::PAGE_TITLE);
        } catch (InvalidArgumentException $e) {
            $data = $this->availableViewData($workOrder, $claimingTechnician);
            $data['submitError'] = $e->getMessage();

            return view('work-orders.claim', $data);
        }

        return view('work-orders.claim', [
            'state' => 'submitted',
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            // v0.13.4.2.2 — tombol lanjut AKTIVASI di halaman sukses klaim.
            // Teknisi yang baru sukses klaim PASTI pengklaim.
            'activationUrl' => $this->activationUrlFor($workOrder, $claimingTechnician),
        ]);
    }

    /**
     * Kartu status untuk state yang BUKAN form/claimed-by-me: terminal
     * (SELESAI/DIBATALKAN) atau sudah diambil teknisi LAIN. null = lanjut ke
     * form / claimed-by-me. Terminal didahulukan (Completed selalu juga
     * ter-claim, tapi harus tampil "SELESAI" bukan "sudah diambil").
     */
    private function statusCardForClaim(WorkOrder $workOrder, Technician $claimingTechnician): ?View
    {
        if ($workOrder->status === WorkOrderStatus::Completed) {
            return $this->completedCard($workOrder, self::PAGE_TITLE);
        }

        if ($workOrder->status === WorkOrderStatus::Cancelled) {
            return $this->cancelledCard($workOrder, self::PAGE_TITLE);
        }

        if ($workOrder->claimed_at !== null
            && (int) $workOrder->claimed_by_technician_id !== (int) $claimingTechnician->id) {
            return $this->claimedByOtherCard($workOrder, self::PAGE_TITLE);
        }

        return null;
    }

    /**
     * v0.13.4.2.2 — signed-link lanjut ke form AKTIVASI (unik per teknisi, 2
     * hari, pola identik claim link). Pola sama `WorkOrderDispatchService::
     * notifyActivationReady()`.
     */
    private function activationUrlFor(WorkOrder $workOrder, Technician $technician): string
    {
        return URL::temporarySignedRoute(
            'work-orders.activate.show',
            now()->addDays(2),
            ['work_order' => $workOrder->id, 'technician' => $technician->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function availableViewData(WorkOrder $workOrder, Technician $claimingTechnician): array
    {
        return [
            'state' => 'available',
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            'partnerOptions' => $this->partnerOptions($workOrder, $claimingTechnician),
            'toolTypes' => ToolType::query()
                ->where('tenant_id', $workOrder->tenant_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimedByMeViewData(WorkOrder $workOrder, Technician $claimingTechnician): array
    {
        $workOrder->loadMissing([
            'claimPartners',
            'toolUsages.toolType',
            'toolUsages.technician',
            'modemUnits.technician',
        ]);

        return [
            'state' => 'claimed_by_me',
            'workOrder' => $workOrder,
            'claimingTechnician' => $claimingTechnician,
            'activationUrl' => $this->activationUrlFor($workOrder, $claimingTechnician),
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
