<?php

namespace App\Livewire\Network;

use App\Livewire\Concerns\StagesPhotoUploads;
use App\Models\FiberNode;
use App\Models\Splitter;
use App\Services\Network\FiberTopologyService;
use App\Services\Network\SplitterLossReferenceService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management. A SEPARATE Livewire
 * component from FiberNodeIndex (unlike the "Profil Paket" cluster's
 * mega-components).
 *
 * Langkah 3: create mode had plain lat/long inputs and a "GPS & foto
 * tersedia setelah disimpan" note, because the reusable GpsPhotoCapture
 * widget needs an already-persisted owner.
 *
 * Langkah 5: that gap is closed here directly. Create mode now carries the
 * Leaflet picker, "Ambil lokasi saya", a photo picker (Livewire temporary
 * uploads, previewed before save) and — for an ODC — a splitter sub-form;
 * on save, node + photos + splitter are written in ONE transaction
 * (FiberTopologyService::createNodeWithAttachments). Edit mode keeps
 * delegating GPS + photos to the nested GpsPhotoCapture (which itself
 * gained the map), and shows the same splitter sub-form against the
 * already-saved node.
 */
class FiberNodeForm extends Component
{
    use StagesPhotoUploads;

    public ?int $fiberNodeId = null;

    public string $nodeType = 'otb';

    public string $localLabel = '';

    public string $parentId = '';

    public string $parentType = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $lossInDb = '';

    public string $lossOutDb = '';

    public string $portCount = '';

    public string $notes = '';

    public string $splitterRatio = '';

    public string $splitterModel = '';

    /** @var array<int, array{id: int, label: string}> */
    public array $parentOptions = [];

    public function mount(?FiberNode $fiber_node = null): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );

        if ($fiber_node !== null && $fiber_node->exists) {
            $this->fiberNodeId = $fiber_node->id;
            $this->nodeType = $fiber_node->node_type->value;
            $this->localLabel = (string) $fiber_node->local_label;
            $this->parentType = (string) $fiber_node->parent_type;
            $this->parentId = $fiber_node->parent_id !== null ? (string) $fiber_node->parent_id : '';
            $this->latitude = $fiber_node->latitude !== null ? (string) $fiber_node->latitude : '';
            $this->longitude = $fiber_node->longitude !== null ? (string) $fiber_node->longitude : '';
            $this->lossInDb = $fiber_node->loss_in_db !== null ? (string) $fiber_node->loss_in_db : '';
            $this->lossOutDb = $fiber_node->loss_out_db !== null ? (string) $fiber_node->loss_out_db : '';
            $this->portCount = $fiber_node->port_count !== null ? (string) $fiber_node->port_count : '';
            $this->notes = (string) $fiber_node->notes;
        }

        $this->parentOptions = FiberNode::query()
            ->when($this->fiberNodeId !== null, fn ($query) => $query->whereKeyNot($this->fiberNodeId))
            ->orderBy('local_label')
            ->get()
            ->map(fn (FiberNode $node) => ['id' => $node->id, 'label' => $node->local_label ?? $node->node_type->label()])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'nodeType' => ['required', 'string', Rule::in(['otb', 'closure', 'odc'])],
            'localLabel' => ['nullable', 'string', 'max:255'],
            'parentId' => ['nullable', 'integer'],
            'lossInDb' => ['nullable', 'numeric'],
            'lossOutDb' => ['nullable', 'numeric'],
            'portCount' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string'],
            'splitterRatio' => ['nullable', 'string', 'max:20'],
            'splitterModel' => ['nullable', 'string', 'max:100'],
            'newPhotos' => ['array'],
            'newPhotos.*' => ['image', 'max:20480'],
            ...($this->fiberNodeId === null ? [
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ] : []),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'nodeType' => 'Tipe Titik',
            'localLabel' => 'Label',
            'lossInDb' => 'Redaman Masuk',
            'lossOutDb' => 'Redaman Keluar',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'portCount' => 'Jumlah Port',
            'splitterRatio' => 'Rasio Splitter',
            'splitterModel' => 'Model Splitter',
        ];
    }

    public function deleteSplitter(int $splitterId, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $splitter = Splitter::query()
            ->tenantScoped()
            ->where('owner_type', FiberNode::class)
            ->where('owner_id', $this->fiberNodeId)
            ->findOrFail($splitterId);

        $service->deleteSplitter($splitter);
    }

    public function save(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        $isSplittingPoint = $this->nodeType === 'odc';
        $isOtb = $this->nodeType === 'otb';

        if ($isSplittingPoint) {
            if ($this->lossInDb === '') {
                $this->addError('lossInDb', 'Redaman masuk (loss in) wajib diisi untuk titik ODC.');
            }

            if ($this->lossOutDb === '') {
                $this->addError('lossOutDb', 'Redaman keluar (loss out) wajib diisi untuk titik ODC.');
            }

            if ($this->getErrorBag()->hasAny(['lossInDb', 'lossOutDb'])) {
                return;
            }
        }

        if ($isOtb && $this->portCount === '') {
            $this->addError('portCount', 'Jumlah port wajib diisi untuk titik OTB.');

            return;
        }

        $data = [
            'node_type' => $this->nodeType,
            'local_label' => $this->localLabel !== '' ? $this->localLabel : null,
            'parent_type' => $this->parentId !== '' ? FiberNode::class : null,
            'parent_id' => $this->parentId !== '' ? (int) $this->parentId : null,
            'loss_in_db' => $this->lossInDb !== '' ? (float) $this->lossInDb : null,
            'loss_out_db' => $this->lossOutDb !== '' ? (float) $this->lossOutDb : null,
            'port_count' => ($isOtb && $this->portCount !== '') ? (int) $this->portCount : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        // A splitter only ever makes sense on a splitting point (ODC) —
        // ignore any stale ratio the field may still hold if the type was
        // switched away from ODC before saving.
        $splitter = ($isSplittingPoint && $this->splitterRatio !== '')
            ? ['ratio' => $this->splitterRatio, 'model' => $this->splitterModel]
            : null;

        if ($this->fiberNodeId === null) {
            $data['latitude'] = $this->latitude !== '' ? (float) $this->latitude : null;
            $data['longitude'] = $this->longitude !== '' ? (float) $this->longitude : null;

            $node = $service->createNodeWithAttachments($data, $this->newPhotos, $splitter);

            $this->newPhotos = [];
            $this->dispatch('fiber-node-saved');
            session()->flash('status', 'Titik topologi fiber berhasil dibuat.');
            // Langkah 7 fix — redirect to the list on a successful save
            // (was: into edit mode; create mode now handles GPS/photo/
            // splitter itself since Langkah 5, so there's nothing left to
            // do on an edit page right after create).
            $this->redirectRoute('web.fiber-nodes.index', navigate: true);

            return;
        }

        $node = $service->updateNode(FiberNode::findOrFail($this->fiberNodeId), $data);

        if ($splitter !== null) {
            $service->attachSplitter($node, $splitter);
            $this->splitterRatio = '';
            $this->splitterModel = '';
        }

        $this->dispatch('fiber-node-saved');
        session()->flash('status', 'Titik topologi fiber berhasil diperbarui.');
        $this->redirectRoute('web.fiber-nodes.index', navigate: true);
    }

    public function render(FiberTopologyService $service, SplitterLossReferenceService $lossReference)
    {
        $mapPoints = $this->fiberNodeId === null
            ? $service->mapReferencePoints()
            : [];

        $splitters = $this->fiberNodeId !== null
            ? Splitter::query()
                ->tenantScoped()
                ->where('owner_type', FiberNode::class)
                ->where('owner_id', $this->fiberNodeId)
                ->latest()
                ->get()
            : collect();

        return view('livewire.network.fiber-node-form', [
            'mapPoints' => $mapPoints,
            'splitters' => $splitters,
            'ratioSuggestions' => $lossReference->suggestedRatios(),
        ]);
    }
}
