<?php

namespace App\Livewire\Network;

use App\Models\FiberNode;
use App\Services\Network\FiberTopologyService;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. Lists
 * fiber_nodes (OTB/Closure/ODC) AND odps (ODP) in one combined table —
 * the actual union query lives in FiberTopologyService::listTopologyPoints()
 * per BOSS-006, this component only calls it and renders the result.
 */
class FiberNodeIndex extends Component
{
    public string $nodeTypeFilter = '';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );
    }

    public function deleteNode(int $id, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $service->deleteNode(FiberNode::findOrFail($id));
    }

    public function render(FiberTopologyService $service)
    {
        $points = $service->listTopologyPoints(
            $this->nodeTypeFilter !== '' ? $this->nodeTypeFilter : null,
            $this->search !== '' ? $this->search : null,
        );

        return view('livewire.network.fiber-node-index', [
            'points' => $points,
            'canManage' => auth()->user()->can('network_infrastructure.manage'),
        ]);
    }
}
