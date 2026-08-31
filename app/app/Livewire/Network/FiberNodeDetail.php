<?php

namespace App\Livewire\Network;

use App\Models\FiberNode;
use App\Models\Odp;
use App\Services\Network\FiberColorService;
use App\Services\Network\FiberTopologyService;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Renders one
 * node's splice diagram — accepts EITHER a FiberNode OR an Odp as the
 * center (two routes, one component — see routes/web.php), since both
 * are valid splice points in the cable graph (see FiberTopologyService::
 * spliceDiagramData()'s own docblock).
 */
class FiberNodeDetail extends Component
{
    public string $targetType;

    public int $targetId;

    public function mount(?FiberNode $fiber_node = null, ?Odp $odp = null): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );

        if ($fiber_node !== null && $fiber_node->exists) {
            $this->targetType = FiberNode::class;
            $this->targetId = $fiber_node->id;

            return;
        }

        if ($odp !== null && $odp->exists) {
            $this->targetType = Odp::class;
            $this->targetId = $odp->id;

            return;
        }

        abort(404);
    }

    public function render(FiberTopologyService $service, FiberColorService $colorService)
    {
        $target = $this->targetType === FiberNode::class
            ? FiberNode::findOrFail($this->targetId)
            : Odp::findOrFail($this->targetId);

        $data = $service->spliceDiagramData($target);

        return view('livewire.network.fiber-node-detail', [
            ...$data,
            'colorService' => $colorService,
        ]);
    }
}
