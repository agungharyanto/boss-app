<?php

namespace App\Livewire\Network;

use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Odp;
use App\Services\Network\FiberColorService;
use App\Services\Network\FiberTopologyService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Component;

/**
 * v0.16.0 Langkah 5 — add an outgoing cable FROM one topology point
 * (FiberNode OR Odp — two routes, one component, same shape as
 * FiberNodeDetail). Reached from FiberNodeDetail's "Tambah kabel keluar"
 * button.
 *
 * The even-core / tube×core-per-tube checks mirror StoreFiberCableRequest
 * (Langkah 2) so the user gets a clean inline message rather than an
 * exception from FiberTopologyService::createCable() — which independently
 * re-checks the same conditions (defense in depth, established posture).
 *
 * After a successful create the component flips to a review state showing
 * every generated FiberCore (tube/core number + TIA/EIA colour swatch),
 * each row's colour manually overridable.
 */
class FiberCableForm extends Component
{
    public string $sourceType;

    public int $sourceId;

    public string $sourceLabel = '';

    /** Combined "FQCN#id" value of the chosen destination point. */
    public string $toKey = '';

    public string $totalCores = '12';

    public string $tubeCount = '2';

    public string $coresPerTube = '6';

    public ?int $createdCableId = null;

    /** @var array<int, array{tube: string, core: string}> */
    public array $coreEdits = [];

    public function mount(?FiberNode $fiber_node = null, ?Odp $odp = null): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        if ($fiber_node !== null && $fiber_node->exists) {
            $this->sourceType = FiberNode::class;
            $this->sourceId = $fiber_node->id;
            $this->sourceLabel = $fiber_node->local_label ?? $fiber_node->node_type->label();

            return;
        }

        if ($odp !== null && $odp->exists) {
            $this->sourceType = Odp::class;
            $this->sourceId = $odp->id;
            $this->sourceLabel = "{$odp->code} - {$odp->name}";

            return;
        }

        abort(404);
    }

    private function source(): FiberNode|Odp
    {
        return $this->sourceType === FiberNode::class
            ? FiberNode::findOrFail($this->sourceId)
            : Odp::findOrFail($this->sourceId);
    }

    protected function rules(): array
    {
        return [
            'toKey' => ['required', 'string'],
            'totalCores' => ['required', 'integer', 'min:2'],
            'tubeCount' => ['required', 'integer', 'min:1'],
            'coresPerTube' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'toKey' => 'Titik Tujuan',
            'totalCores' => 'Jumlah Core',
            'tubeCount' => 'Jumlah Tube',
            'coresPerTube' => 'Core per Tube',
        ];
    }

    public function save(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        [$toType, $toId] = $this->parseToKey();

        $validTargets = collect($service->cableTargetCandidates($this->source()))
            ->map(fn (array $c) => $c['type'].'#'.$c['id'])
            ->all();

        if (! in_array($this->toKey, $validTargets, true)) {
            $this->addError('toKey', 'Titik tujuan tidak valid atau sudah menjadi anak dari titik ini.');

            return;
        }

        $total = (int) $this->totalCores;
        $tubeTimesCore = (int) $this->tubeCount * (int) $this->coresPerTube;

        if ($total % 2 !== 0) {
            $this->addError('totalCores', 'Jumlah core harus genap.');

            return;
        }

        if ($tubeTimesCore % 2 !== 0) {
            $this->addError('coresPerTube', 'Jumlah tube dikali core per tube harus genap juga.');

            return;
        }

        if ($tubeTimesCore !== $total) {
            $this->addError('coresPerTube', 'Jumlah tube dikali core per tube harus sama dengan jumlah core total.');

            return;
        }

        try {
            $cable = $service->createCable([
                'from_type' => $this->sourceType,
                'from_id' => $this->sourceId,
                'to_type' => $toType,
                'to_id' => $toId,
                'total_cores' => $total,
                'tube_count' => (int) $this->tubeCount,
                'cores_per_tube' => (int) $this->coresPerTube,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('totalCores', $e->getMessage());

            return;
        }

        $this->createdCableId = $cable->id;
        $this->coreEdits = $this->cores()
            ->mapWithKeys(fn (FiberCore $core) => [$core->id => [
                'tube' => (string) $core->tube_color,
                'core' => (string) $core->core_color,
            ]])
            ->all();

        session()->flash('status', "Kabel #{$cable->id} berhasil dibuat dengan {$cable->total_cores} core.");
    }

    public function saveCoreColors(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        foreach ($this->cores() as $core) {
            $edit = $this->coreEdits[$core->id] ?? null;

            if ($edit === null) {
                continue;
            }

            $service->overrideCoreColor($core, $edit['tube'] ?? null, $edit['core'] ?? null);
        }

        session()->flash('status', 'Warna core disimpan.');
        // Langkah 7 fix — the core-colour review is the terminal step of
        // this form; on a successful save go back to the source node's
        // detail page rather than leaving the technician on the review
        // table to hunt for the "Selesai" link. (Cable creation itself,
        // save(), deliberately still shows the review inline first.)
        $this->redirectRoute(
            $this->sourceType === FiberNode::class ? 'web.fiber-nodes.detail' : 'web.odps.detail',
            [$this->sourceType === FiberNode::class ? 'fiber_node' : 'odp' => $this->sourceId],
            navigate: true,
        );
    }

    /**
     * @return Collection<int, FiberCore>
     */
    private function cores(): Collection
    {
        if ($this->createdCableId === null) {
            return collect();
        }

        return FiberCore::where('fiber_cable_id', $this->createdCableId)
            ->orderBy('tube_number')
            ->orderBy('core_number_in_tube')
            ->get();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseToKey(): array
    {
        $parts = explode('#', $this->toKey, 2);

        return [$parts[0] ?? '', (int) ($parts[1] ?? 0)];
    }

    public function render(FiberTopologyService $service, FiberColorService $colorService)
    {
        return view('livewire.network.fiber-cable-form', [
            'candidates' => $this->createdCableId === null ? $service->cableTargetCandidates($this->source()) : [],
            'cores' => $this->cores(),
            'colorService' => $colorService,
        ]);
    }
}
