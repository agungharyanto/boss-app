<?php

namespace App\Livewire\Network;

use App\Models\FiberNode;
use App\Services\Network\FiberTopologyService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. Deliberately
 * a SEPARATE Livewire component from FiberNodeIndex (unlike every other
 * module in the "Profil Paket" cluster, which bakes create/edit into one
 * mega-component) — this is what lets the embedded GpsPhotoCapture widget
 * (edit mode only, see that component's own docblock) be reused verbatim
 * on App\Livewire\Installation\OdpEdit too.
 *
 * Create mode has its own plain latitude/longitude inputs (GpsPhotoCapture
 * always needs an already-persisted owner, which a brand-new node doesn't
 * have yet) — on successful create, the form redirects into edit mode for
 * the newly created node, where GpsPhotoCapture then takes over GPS/photo
 * management for the rest of that node's life.
 */
class FiberNodeForm extends Component
{
    public ?int $fiberNodeId = null;

    public string $nodeType = 'otb';

    public string $localLabel = '';

    public string $parentType = '';

    public string $parentId = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $lossInDb = '';

    public string $lossOutDb = '';

    public string $notes = '';

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
            'notes' => ['nullable', 'string'],
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
        ];
    }

    public function save(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        $target = new FiberNode(['node_type' => $this->nodeType]);

        if ($service->isLossRequired($target)) {
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

        $data = [
            'node_type' => $this->nodeType,
            'local_label' => $this->localLabel !== '' ? $this->localLabel : null,
            'parent_type' => $this->parentId !== '' ? FiberNode::class : null,
            'parent_id' => $this->parentId !== '' ? (int) $this->parentId : null,
            'loss_in_db' => $this->lossInDb !== '' ? (float) $this->lossInDb : null,
            'loss_out_db' => $this->lossOutDb !== '' ? (float) $this->lossOutDb : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        if ($this->fiberNodeId === null) {
            $data['latitude'] = $this->latitude !== '' ? (float) $this->latitude : null;
            $data['longitude'] = $this->longitude !== '' ? (float) $this->longitude : null;

            $node = $service->createNode($data);
            $this->dispatch('fiber-node-saved');
            session()->flash('status', 'Titik topologi fiber berhasil dibuat.');
            $this->redirectRoute('web.fiber-nodes.edit', ['fiber_node' => $node->id], navigate: true);

            return;
        }

        $service->updateNode(FiberNode::findOrFail($this->fiberNodeId), $data);
        $this->dispatch('fiber-node-saved');
        session()->flash('status', 'Titik topologi fiber berhasil diperbarui.');
    }

    public function render()
    {
        return view('livewire.network.fiber-node-form');
    }
}
