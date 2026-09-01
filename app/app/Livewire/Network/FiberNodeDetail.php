<?php

namespace App\Livewire\Network;

use App\Enums\FiberAccessoryType;
use App\Enums\FiberNodeType;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Services\Network\FiberColorService;
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
            'coreConnections' => $service->cableCoreConnections($target),
            'topologyService' => $service,
        ]);
    }
}
