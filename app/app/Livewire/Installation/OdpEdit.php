<?php

namespace App\Livewire\Installation;

use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Splitter;
use App\Services\Network\FiberTopologyService;
use App\Services\Network\SplitterLossReferenceService;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. A genuinely
 * NEW page — confirmed by grep before building that no Odp web edit page
 * existed anywhere in this codebase (Odp has only ever been API-only,
 * v0.5.0's OdpController). This page does NOT touch StoreOdpRequest/
 * UpdateOdpRequest/OdpController at all — Odp's own core fields
 * (code/name/total_ports, the v0.5.0 registration flow) are shown
 * read-only here; only the NEW v0.16.0 fields (parent link, loss, GPS,
 * photos) are editable, via FiberTopologyService::updateOdpTopologyFields()
 * and the same reusable GpsPhotoCapture widget FiberNodeForm uses.
 */
class OdpEdit extends Component
{
    public int $odpId;

    public string $code = '';

    public string $name = '';

    public string $parentId = '';

    public string $lossInDb = '';

    public string $lossOutDb = '';

    public string $splitterRatio = '';

    public string $splitterModel = '';

    /** @var array<int, array{id: int, label: string}> */
    public array $parentOptions = [];

    public function mount(Odp $odp): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );

        $this->odpId = $odp->id;
        $this->code = $odp->code;
        $this->name = $odp->name;
        $this->parentId = $odp->parent_id !== null ? (string) $odp->parent_id : '';
        $this->lossInDb = $odp->loss_in_db !== null ? (string) $odp->loss_in_db : '';
        $this->lossOutDb = $odp->loss_out_db !== null ? (string) $odp->loss_out_db : '';

        $this->parentOptions = FiberNode::query()
            ->orderBy('local_label')
            ->get()
            ->map(fn (FiberNode $node) => ['id' => $node->id, 'label' => $node->local_label ?? $node->node_type->label()])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'parentId' => ['nullable', 'integer'],
            'lossInDb' => ['required', 'numeric'],
            'lossOutDb' => ['required', 'numeric'],
            'splitterRatio' => ['nullable', 'string', 'max:20'],
            'splitterModel' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'lossInDb' => 'Redaman Masuk',
            'lossOutDb' => 'Redaman Keluar',
            'splitterRatio' => 'Rasio Splitter',
            'splitterModel' => 'Model Splitter',
        ];
    }

    public function deleteSplitter(int $splitterId, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $splitter = Splitter::query()
            ->tenantScoped()
            ->where('owner_type', Odp::class)
            ->where('owner_id', $this->odpId)
            ->findOrFail($splitterId);

        $service->deleteSplitter($splitter);
    }

    public function save(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        $odp = $service->updateOdpTopologyFields(Odp::findOrFail($this->odpId), [
            'parent_type' => $this->parentId !== '' ? FiberNode::class : null,
            'parent_id' => $this->parentId !== '' ? (int) $this->parentId : null,
            'loss_in_db' => (float) $this->lossInDb,
            'loss_out_db' => (float) $this->lossOutDb,
        ]);

        if ($this->splitterRatio !== '') {
            $service->attachSplitter($odp, ['ratio' => $this->splitterRatio, 'model' => $this->splitterModel]);
            $this->splitterRatio = '';
            $this->splitterModel = '';
        }

        session()->flash('status', 'Data topologi ODP berhasil diperbarui.');
        // Langkah 7 fix — back to the topology list on success. No
        // dedicated Odp web list exists (Odp is API-only in v0.5.0, see
        // routes/api.php) — the combined "Topologi Fiber" page
        // (FiberNodeIndex) is where ODP rows are listed and where the
        // "Edit" link that opens this page lives.
        $this->redirectRoute('web.fiber-nodes.index', navigate: true);
    }

    /**
     * v0.16.0 Langkah 4 — lat/long re-fetched fresh on every render purely
     * for the Google Maps direction link (GpsPhotoCapture, a separate
     * nested Livewire component, owns the actual GPS editing — see that
     * component's own docblock). Known minor limitation: since Livewire
     * doesn't automatically re-render a parent when a nested child's own
     * state changes, this link can show a stale coordinate until the next
     * full page load/parent-level action after a GPS update — acceptable
     * for this Langkah, not asked to be made reactive.
     */
    public function render(SplitterLossReferenceService $lossReference)
    {
        $odp = Odp::findOrFail($this->odpId);

        return view('livewire.installation.odp-edit', [
            'latitude' => $odp->latitude,
            'longitude' => $odp->longitude,
            'splitters' => Splitter::query()
                ->tenantScoped()
                ->where('owner_type', Odp::class)
                ->where('owner_id', $this->odpId)
                ->latest()
                ->get(),
            'ratioSuggestions' => $lossReference->suggestedRatios(),
        ]);
    }
}
