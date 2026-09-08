<?php

namespace App\Livewire\Network;

use App\Enums\FiberAccessoryType;
use App\Enums\FiberNodeType;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberCoreSplice;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Services\Network\FiberColorService;
use App\Services\Network\FiberCoreSpliceService;
use App\Services\Network\FiberTopologyService;
use App\Services\Network\SplitterLossReferenceService;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management. Renders one node's
 * splice diagram — FiberNode OR Odp (two routes, one component).
 *
 * Langkah 6: OTB port patch simulation + per-row assignment.
 * Langkah 7: bulk "Simpan Semua", auto-release on reassign, an OLT
 * direct-patch link per port, a light audit trail, and a "+ Tambah
 * Aksesori" form in the accessories section.
 */
class FiberNodeDetail extends Component
{
    public string $targetType;

    public int $targetId;

    /** @var array<int, string> coreId => port_number as string */
    public array $portInputs = [];

    /** @var array<int, string> coreId => olt_device_id as string */
    public array $oltDeviceInputs = [];

    /** @var array<int, string> coreId => PON port label */
    public array $oltPonInputs = [];

    public bool $showAccessoryForm = false;

    public string $accTargetKey = '';

    public string $accType = '';

    public string $accLocation = '';

    public string $accExpectedLoss = '';

    public string $accMeasuredLoss = '';

    /* v0.16.1 Bagian D — Assign Core-to-Core (non-OTB nodes) */
    public string $spliceCableA = '';

    public string $spliceCoreA = '';

    public string $spliceCableB = '';

    public string $spliceCoreB = '';

    public string $spliceLoss = '';

    public string $spliceNote = '';

    public function mount(?FiberNode $fiber_node = null, ?Odp $odp = null, ?FiberTopologyService $service = null): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );

        if ($fiber_node !== null && $fiber_node->exists) {
            $this->targetType = FiberNode::class;
            $this->targetId = $fiber_node->id;

            if ($fiber_node->node_type === FiberNodeType::Otb && $service !== null) {
                foreach ($service->coresFromNode($fiber_node) as $core) {
                    $this->portInputs[$core->id] = (string) ($core->port_number ?? '');
                    $this->oltDeviceInputs[$core->id] = (string) ($core->olt_device_id ?? '');
                    $this->oltPonInputs[$core->id] = (string) ($core->olt_pon_port_label ?? '');
                }
            }

            return;
        }

        if ($odp !== null && $odp->exists) {
            $this->targetType = Odp::class;
            $this->targetId = $odp->id;

            return;
        }

        abort(404);
    }

    private function target(): FiberNode|Odp
    {
        return $this->targetType === FiberNode::class
            ? FiberNode::findOrFail($this->targetId)
            : Odp::findOrFail($this->targetId);
    }

    private function rowInput(int $coreId): array
    {
        return [
            'port' => $this->portInputs[$coreId] ?? '',
            'olt_device_id' => $this->oltDeviceInputs[$coreId] ?? '',
            'olt_pon_port_label' => $this->oltPonInputs[$coreId] ?? '',
        ];
    }

    /** Per-row quick save (optional path — Simpan Semua is the primary one). */
    public function assignPort(int $coreId, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);
        abort_unless($this->targetType === FiberNode::class, 400);

        $otb = FiberNode::findOrFail($this->targetId);
        $core = FiberCore::findOrFail($coreId);
        $row = $this->rowInput($coreId);
        $port = trim((string) $row['port']) === '' ? null : (int) $row['port'];

        try {
            $service->assignCorePort(
                $core,
                $otb,
                $port,
                ($row['olt_device_id'] === '') ? null : (int) $row['olt_device_id'],
                trim((string) $row['olt_pon_port_label']) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError("portInputs.{$coreId}", $e->getMessage());

            return;
        }

        session()->flash('port-status', 'Patching port disimpan.');
    }

    /** Primary path — validate & save EVERY row at once, all-or-nothing. */
    public function saveAllPorts(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);
        abort_unless($this->targetType === FiberNode::class, 400);

        $otb = FiberNode::findOrFail($this->targetId);

        $rows = [];
        foreach (array_keys($this->portInputs) as $coreId) {
            $rows[(int) $coreId] = $this->rowInput((int) $coreId);
        }

        $errors = $service->assignCorePorts($otb, $rows);

        if ($errors !== []) {
            foreach ($errors as $coreId => $message) {
                $this->addError("portInputs.{$coreId}", $message);
            }

            return;
        }

        session()->flash('port-status', 'Semua patching port disimpan.');
    }

    public function updatedAccType(): void
    {
        $this->prefillAccessoryLoss();
    }

    public function updatedAccTargetKey(): void
    {
        $this->prefillAccessoryLoss();
    }

    private function prefillAccessoryLoss(): void
    {
        $suggested = app(FiberTopologyService::class)->suggestedAccessoryLoss(
            $this->accTargetKey ?: null,
            $this->accType ?: null,
            app(SplitterLossReferenceService::class),
        );

        $this->accExpectedLoss = $suggested !== null ? (string) $suggested : '';
    }

    public function addAccessory(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate([
            'accTargetKey' => ['required', 'string', Rule::in(collect($service->accessoryTargetsForNode($this->target()))->pluck('key')->all())],
            'accType' => ['required', Rule::in(array_column(FiberAccessoryType::cases(), 'value'))],
            'accLocation' => ['nullable', 'string', 'max:255'],
            'accExpectedLoss' => ['nullable', 'numeric'],
            'accMeasuredLoss' => ['required', 'numeric'],
        ], [], [
            'accTargetKey' => 'Terpasang di',
            'accType' => 'Tipe Aksesori',
            'accMeasuredLoss' => 'Redaman Terukur',
        ]);

        [$kind, $id] = explode('#', $this->accTargetKey, 2);

        $service->createAccessory([
            'fiber_cable_id' => $kind === 'cable' ? (int) $id : null,
            'splitter_id' => $kind === 'splitter' ? (int) $id : null,
            'accessory_type' => $this->accType,
            'expected_loss_db' => $this->accExpectedLoss !== '' ? (float) $this->accExpectedLoss : null,
            'measured_loss_db' => (float) $this->accMeasuredLoss,
            'location_note' => $this->accLocation !== '' ? $this->accLocation : null,
        ]);

        $this->reset('accTargetKey', 'accType', 'accLocation', 'accExpectedLoss', 'accMeasuredLoss', 'showAccessoryForm');
        session()->flash('accessory-status', 'Aksesori berhasil ditambahkan.');
    }

    /* ---- v0.16.1 Bagian D — Assign Core-to-Core (Closure/ODC/ODP) ---- */

    /** Reset the dependent core dropdown when its cable changes. */
    public function updatedSpliceCableA(): void
    {
        $this->spliceCoreA = '';
    }

    public function updatedSpliceCableB(): void
    {
        $this->spliceCoreB = '';
    }

    /**
     * Cores of $cableId that are NOT already in a splice — the options
     * for one side of the "Sambungkan" form.
     *
     * @return list<array{id: int, label: string}>
     */
    public function spliceCoreOptions(int $cableId): array
    {
        $cable = FiberCable::with('cores')->find($cableId);

        if ($cable === null || ! $this->cableTouchesTarget($cable)) {
            return [];
        }

        $takenIds = FiberCoreSplice::query()
            ->where(fn ($q) => $q->whereIn('from_fiber_core_id', $cable->cores->pluck('id'))
                ->orWhereIn('to_fiber_core_id', $cable->cores->pluck('id')))
            ->get()
            ->flatMap(fn (FiberCoreSplice $s) => [$s->from_fiber_core_id, $s->to_fiber_core_id])
            ->all();

        return $cable->cores
            ->sortBy(['tube_number', 'core_number_in_tube'])
            ->reject(fn (FiberCore $c) => in_array($c->id, $takenIds, true))
            ->map(fn (FiberCore $c) => [
                'id' => $c->id,
                'label' => "T{$c->tube_number}/C{$c->core_number_in_tube}"
                    .($c->core_color !== null ? " ({$c->core_color})" : ''),
            ])
            ->values()
            ->all();
    }

    public function createSplice(FiberCoreSpliceService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate([
            'spliceCableA' => ['required', 'integer'],
            'spliceCoreA' => ['required', 'integer'],
            'spliceCableB' => ['required', 'integer', 'different:spliceCableA'],
            'spliceCoreB' => ['required', 'integer'],
            'spliceLoss' => ['nullable', 'numeric', 'min:0'],
            'spliceNote' => ['nullable', 'string', 'max:255'],
        ], [
            'spliceCableB.different' => 'Pilih dua kabel yang berbeda.',
        ], [
            'spliceCableA' => 'Kabel sisi awal',
            'spliceCoreA' => 'Core sisi awal',
            'spliceCableB' => 'Kabel sisi akhir',
            'spliceCoreB' => 'Core sisi akhir',
            'spliceLoss' => 'Redaman',
        ]);

        $from = FiberCore::findOrFail((int) $this->spliceCoreA);
        $to = FiberCore::findOrFail((int) $this->spliceCoreB);

        try {
            $service->createSplice(
                $this->target(),
                $from,
                $to,
                $this->spliceLoss !== '' ? (float) $this->spliceLoss : null,
                $this->spliceNote !== '' ? $this->spliceNote : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('spliceCoreB', $e->getMessage());

            return;
        }

        $this->reset('spliceCableA', 'spliceCoreA', 'spliceCableB', 'spliceCoreB', 'spliceLoss', 'spliceNote');
        session()->flash('splice-status', 'Splice core-to-core disimpan.');
    }

    public function removeSplice(int $spliceId, FiberCoreSpliceService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $splice = FiberCoreSplice::query()
            ->where('splice_node_type', $this->targetType)
            ->where('splice_node_id', $this->targetId)
            ->findOrFail($spliceId);

        $service->deleteSplice($splice);
        session()->flash('splice-status', 'Splice dihapus.');
    }

    private function cableTouchesTarget(FiberCable $cable): bool
    {
        return ($cable->from_type === $this->targetType && (int) $cable->from_id === $this->targetId)
            || ($cable->to_type === $this->targetType && (int) $cable->to_id === $this->targetId);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function spliceCableOptions(FiberTopologyService $service): array
    {
        $target = $this->target();

        return $target->cablesAsFrom->concat($target->cablesAsTo)
            ->unique('id')
            ->map(fn (FiberCable $c) => ['id' => $c->id, 'label' => $service->describeCable($c)])
            ->values()
            ->all();
    }

    public function render(FiberTopologyService $service, FiberColorService $colorService)
    {
        $target = $this->target();
        $data = $service->spliceDiagramData($target);

        $isOtb = $target instanceof FiberNode && $target->node_type === FiberNodeType::Otb;
        $assignableCores = $isOtb ? $service->assignableOtbCores($target) : [];

        foreach ($assignableCores as $core) {
            $this->portInputs[$core['core_id']] ??= (string) ($core['port_number'] ?? '');
            $this->oltDeviceInputs[$core['core_id']] ??= (string) ($core['olt_device_id'] ?? '');
            $this->oltPonInputs[$core['core_id']] ??= (string) ($core['olt_pon_port_label'] ?? '');
        }

        $spliceCableOptions = $isOtb ? [] : $this->spliceCableOptions($service);

        return view('livewire.network.fiber-node-detail', [
            ...$data,
            'colorService' => $colorService,
            'isOtb' => $isOtb,
            'portCount' => $isOtb ? (int) ($target->port_count ?? 0) : 0,
            'portSimulation' => $isOtb ? $service->otbPortSimulation($target) : [],
            'assignableCores' => $assignableCores,
            'oltOptions' => $isOtb ? $service->oltDeviceOptions() : [],
            'portLogs' => $isOtb ? $service->otbPortLogs($target, 3) : collect(),
            'accessoryTargets' => $service->accessoryTargetsForNode($target),
            'accessoryTypes' => FiberAccessoryType::cases(),
            'coreGrid' => $service->coreGridForNode($target, $isOtb ? $target : null),
            'splices' => $isOtb ? collect() : app(FiberCoreSpliceService::class)->splicesForNode($target),
            'spliceCableOptions' => $spliceCableOptions,
            'spliceCoreAOptions' => (! $isOtb && $this->spliceCableA !== '') ? $this->spliceCoreOptions((int) $this->spliceCableA) : [],
            'spliceCoreBOptions' => (! $isOtb && $this->spliceCableB !== '') ? $this->spliceCoreOptions((int) $this->spliceCableB) : [],
            'topologyService' => $service,
        ]);
    }
}
