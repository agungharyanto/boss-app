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

    /* v0.16.1 Revisi E — cari baris di tabel "Assign Port ke Core" */
    public string $portSearch = '';

    /* v0.16.1 Revisi 2 C — "Tukar Port" modal 2-tingkat (mode Antar Core
       / Antar Tube). Menggantikan checkbox+swap inline dari Revisi E. */
    public bool $showSwapModal = false;

    public string $swapMode = '';        // '' | 'core' | 'tube'

    public string $swapCableId = '';     // tube mode: kabel mana

    public string $swapSourceTube = '';

    public string $swapTargetTube = '';

    public string $swapCoreSearch = '';  // core mode: filter dropdown

    public string $swapSourceCore = '';

    public string $swapTargetCore = '';

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

    /* ---- v0.16.1 Revisi 2 C — "Tukar Port" modal 2-tingkat ---- */

    public function openSwapModal(): void
    {
        abort_unless($this->targetType === FiberNode::class, 400);

        $this->resetSwapModal();
        $this->showSwapModal = true;
    }

    public function closeSwapModal(): void
    {
        $this->resetSwapModal();
        $this->showSwapModal = false;
    }

    private function resetSwapModal(): void
    {
        $this->swapMode = '';
        $this->swapCableId = '';
        $this->swapSourceTube = '';
        $this->swapTargetTube = '';
        $this->swapCoreSearch = '';
        $this->swapSourceCore = '';
        $this->swapTargetCore = '';
        $this->resetErrorBag(['swapCableId', 'swapSourceTube', 'swapTargetTube', 'swapSourceCore', 'swapTargetCore']);
    }

    public function chooseSwapMode(string $mode): void
    {
        $this->swapMode = in_array($mode, ['core', 'tube'], true) ? $mode : '';
        $this->swapCableId = '';
        $this->swapSourceTube = '';
        $this->swapTargetTube = '';
        $this->swapSourceCore = '';
        $this->swapTargetCore = '';
    }

    public function updatedSwapCableId(): void
    {
        $this->swapSourceTube = '';
        $this->swapTargetTube = '';
    }

    public function updatedSwapSourceTube(): void
    {
        $this->swapTargetTube = '';
    }

    public function confirmSwapCores(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);
        abort_unless($this->targetType === FiberNode::class, 400);

        $this->validate([
            'swapSourceCore' => ['required', 'integer'],
            'swapTargetCore' => ['required', 'integer', 'different:swapSourceCore'],
        ], [
            'swapTargetCore.different' => 'Core sumber dan core tujuan harus berbeda.',
        ], [
            'swapSourceCore' => 'Core sumber',
            'swapTargetCore' => 'Core tujuan',
        ]);

        $otb = FiberNode::findOrFail($this->targetId);

        try {
            $service->swapCorePorts($otb, (int) $this->swapSourceCore, (int) $this->swapTargetCore);
        } catch (InvalidArgumentException $e) {
            $this->addError('swapTargetCore', $e->getMessage());

            return;
        }

        $this->reseedPortInputs($service, $otb);
        $this->closeSwapModal();
        session()->flash('port-status', 'Port dua core berhasil ditukar.');
    }

    public function confirmSwapTubes(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);
        abort_unless($this->targetType === FiberNode::class, 400);

        $this->validate([
            'swapCableId' => ['required', 'integer'],
            'swapSourceTube' => ['required', 'integer'],
            'swapTargetTube' => ['required', 'integer', 'different:swapSourceTube'],
        ], [
            'swapTargetTube.different' => 'Tube sumber dan tube tujuan harus berbeda.',
        ], [
            'swapCableId' => 'Kabel',
            'swapSourceTube' => 'Tube sumber',
            'swapTargetTube' => 'Tube tujuan',
        ]);

        $otb = FiberNode::findOrFail($this->targetId);

        try {
            $service->swapCoreTubes($otb, (int) $this->swapCableId, (int) $this->swapSourceTube, (int) $this->swapTargetTube);
        } catch (InvalidArgumentException $e) {
            $this->addError('swapTargetTube', $e->getMessage());

            return;
        }

        $this->reseedPortInputs($service, $otb);
        $this->closeSwapModal();
        session()->flash('port-status', 'Semua port di tube tersebut berhasil ditukar.');
    }

    private function reseedPortInputs(FiberTopologyService $service, FiberNode $otb): void
    {
        foreach ($service->coresFromNode($otb) as $core) {
            $this->portInputs[$core->id] = (string) ($core->port_number ?? '');
            $this->oltDeviceInputs[$core->id] = (string) ($core->olt_device_id ?? '');
            $this->oltPonInputs[$core->id] = (string) ($core->olt_pon_port_label ?? '');
        }
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
     * Cores of $cableId that are NOT already spliced AT THIS NODE — the
     * options for one side of the "Sambungkan" form. v0.16.1 Revisi 4 A —
     * a core that was through-spliced at its cable's OTHER end is still
     * offered here (that's a valid multi-hop path), it's only hidden once
     * it has a splice at THIS node.
     *
     * @return list<array{id: int, label: string}>
     */
    public function spliceCoreOptions(int $cableId): array
    {
        $cable = FiberCable::with('cores')->find($cableId);

        if ($cable === null || ! $this->cableTouchesTarget($cable)) {
            return [];
        }

        $spliceService = app(FiberCoreSpliceService::class);
        $node = $this->target();

        return $cable->cores
            ->sortBy(['tube_number', 'core_number_in_tube'])
            ->reject(fn (FiberCore $c) => $spliceService->coreAlreadySplicedAtNode($c->id, $node))
            ->map(fn (FiberCore $c) => [
                'id' => $c->id,
                // v0.16.1 Revisi B — full "Tube N (Warna) / Core M (Warna)"
                // label; tube & core colour can differ when a core colour
                // was overridden manually.
                'label' => "Tube {$c->tube_number}".($c->tube_color !== null ? " ({$c->tube_color})" : '')
                    ." / Core {$c->core_number_in_tube}".($c->core_color !== null ? " ({$c->core_color})" : ''),
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
            'spliceCableB.different' => 'Kabel masuk dan kabel keluar harus berbeda.',
        ], [
            'spliceCableA' => 'Kabel Masuk',
            'spliceCoreA' => 'Core sisi masuk',
            'spliceCableB' => 'Kabel Keluar',
            'spliceCoreB' => 'Core sisi keluar',
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
     * v0.16.1 Revisi C — delete a cable shown on this node's "Diagram
     * Splice" (incoming or outgoing). Scoped: the cable must actually
     * touch this node. Cores / splices / port logs / accessories /
     * waypoints of the cable go with it via DB cascade.
     */
    public function deleteCable(int $cableId, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $cable = FiberCable::findOrFail($cableId);

        abort_unless($this->cableTouchesTarget($cable), 403);

        try {
            $service->deleteCable($cable);
        } catch (InvalidArgumentException $e) {
            session()->flash('cable-error', $e->getMessage());

            return;
        }

        session()->flash('cable-status', 'Kabel beserta core, waypoint, penempatan port, dan aksesori terkait sudah dihapus.');
    }

    /**
     * v0.16.1 Revisi 2 B — the "Kabel Masuk" dropdown only ever lists
     * cables whose `to_*` end IS this node (arah masuk); "Kabel Keluar"
     * only lists cables whose `from_*` end IS this node. A wrong-direction
     * cable never appears as an option at all — the user can't pick it to
     * be rejected later. FiberCoreSpliceService's orientation guard stays
     * as a second layer.
     *
     * @return list<array{id: int, label: string}>
     */
    private function spliceIncomingCableOptions(FiberTopologyService $service): array
    {
        return $this->target()->cablesAsTo
            ->map(fn (FiberCable $c) => ['id' => $c->id, 'label' => $service->describeCable($c)])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function spliceOutgoingCableOptions(FiberTopologyService $service): array
    {
        return $this->target()->cablesAsFrom
            ->map(fn (FiberCable $c) => ['id' => $c->id, 'label' => $service->describeCable($c)])
            ->values()
            ->all();
    }

    public function render(FiberTopologyService $service, FiberColorService $colorService)
    {
        $target = $this->target();
        $data = $service->spliceDiagramData($target);

        $isOtb = $target instanceof FiberNode && $target->node_type === FiberNodeType::Otb;
        $allAssignableCores = $isOtb ? $service->assignableOtbCores($target) : [];

        foreach ($allAssignableCores as $core) {
            $this->portInputs[$core['core_id']] ??= (string) ($core['port_number'] ?? '');
            $this->oltDeviceInputs[$core['core_id']] ??= (string) ($core['olt_device_id'] ?? '');
            $this->oltPonInputs[$core['core_id']] ??= (string) ($core['olt_pon_port_label'] ?? '');
        }

        // v0.16.1 Revisi E — filter the visible rows by port number, tube
        // colour name, core colour name, "tube N", or the cable name.
        $needle = mb_strtolower(trim($this->portSearch));
        $assignableCores = $needle === ''
            ? $allAssignableCores
            : array_values(array_filter($allAssignableCores, function (array $c) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($c['port_number'] ?? ''),
                    'port '.(string) ($c['port_number'] ?? ''),
                    'tube '.$c['tube_number'],
                    'core '.$c['core_number_in_tube'],
                    (string) ($c['tube_color'] ?? ''),
                    (string) ($c['core_color'] ?? ''),
                    (string) ($c['cable_description'] ?? ''),
                ])));

                return str_contains($haystack, $needle);
            }));

        // v0.16.1 Revisi 2 B — direction-scoped dropdowns.
        $spliceIncomingCableOptions = $isOtb ? [] : $this->spliceIncomingCableOptions($service);
        $spliceOutgoingCableOptions = $isOtb ? [] : $this->spliceOutgoingCableOptions($service);
        $canSplice = ! $isOtb && $spliceIncomingCableOptions !== [] && $spliceOutgoingCableOptions !== [];

        // v0.16.1 Revisi 2 C — "Tukar Port" modal data.
        $swapCableOptions = [];
        $swapCoreOptions = [];

        if ($isOtb) {
            $swapCableOptions = $target->cablesAsFrom->concat($target->cablesAsTo)
                ->unique('id')
                ->map(fn (FiberCable $c) => [
                    'id' => $c->id,
                    'label' => $service->describeCable($c),
                    'tube_count' => (int) $c->tube_count,
                ])
                ->values()
                ->all();

            $swapNeedle = mb_strtolower(trim($this->swapCoreSearch));
            $swapCoreOptions = collect($allAssignableCores)
                ->map(fn (array $c) => [
                    'id' => $c['core_id'],
                    'label' => $c['cable_description']
                        .' — Tube '.$c['tube_number'].' ('.($c['tube_color'] ?? '?').')'
                        .' / Core '.$c['core_number_in_tube'].' ('.($c['core_color'] ?? '?').')'
                        .($c['port_number'] !== null ? ' — Port '.$c['port_number'] : ' — belum di-port'),
                ])
                ->filter(fn (array $o) => $swapNeedle === '' || str_contains(mb_strtolower($o['label']), $swapNeedle))
                ->values()
                ->all();
        }

        $swapCable = ($isOtb && $this->swapCableId !== '')
            ? collect($swapCableOptions)->firstWhere('id', (int) $this->swapCableId)
            : null;
        $swapTubeCount = $swapCable['tube_count'] ?? 0;

        return view('livewire.network.fiber-node-detail', [
            ...$data,
            'colorService' => $colorService,
            'isOtb' => $isOtb,
            'portCount' => $isOtb ? (int) ($target->port_count ?? 0) : 0,
            'portSimulation' => $isOtb ? $service->otbPortSimulation($target) : [],
            'assignableCores' => $assignableCores,
            'hasAnyAssignableCore' => $isOtb && count($allAssignableCores) > 0,
            'oltOptions' => $isOtb ? $service->oltDeviceOptions() : [],
            'oltPonCounts' => $isOtb ? $service->oltPonPortCounts() : [],
            'portLogs' => $isOtb ? $service->otbPortLogs($target, 3) : collect(),
            'accessoryTargets' => $service->accessoryTargetsForNode($target),
            'accessoryTypes' => FiberAccessoryType::cases(),
            'coreGrid' => $service->coreGridForNode($target, $isOtb ? $target : null),
            'splices' => $isOtb ? collect() : app(FiberCoreSpliceService::class)->splicesForNode($target),
            'spliceIncomingCableOptions' => $spliceIncomingCableOptions,
            'spliceOutgoingCableOptions' => $spliceOutgoingCableOptions,
            'canSplice' => $canSplice,
            'spliceCoreAOptions' => (! $isOtb && $this->spliceCableA !== '') ? $this->spliceCoreOptions((int) $this->spliceCableA) : [],
            'spliceCoreBOptions' => (! $isOtb && $this->spliceCableB !== '') ? $this->spliceCoreOptions((int) $this->spliceCableB) : [],
            'swapCableOptions' => $swapCableOptions,
            'swapCoreOptions' => $swapCoreOptions,
            'swapTubeCount' => $swapTubeCount,
            'topologyService' => $service,
        ]);
    }
}
