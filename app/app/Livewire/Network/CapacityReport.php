<?php

namespace App\Livewire\Network;

use App\Services\Network\FiberTopologyService;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. "Taper
 * Report"-style capacity summary (OSPInsight-inspired) — three
 * categories (ODP ports, splitter output legs, cable cores), each row a
 * simple progress bar. All the actual number-crunching lives in
 * FiberTopologyService::capacityReport() per BOSS-006.
 */
class CapacityReport extends Component
{
    public string $search = '';

    public bool $onlyNearFull = false;

    public function mount(): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );
    }

    public function render(FiberTopologyService $service)
    {
        $report = $service->capacityReport($this->search !== '' ? $this->search : null);

        if ($this->onlyNearFull) {
            $isNearFull = fn (object $row): bool => $row->percent !== null && $row->percent > 80;
            $report = [
                'odps' => $report['odps']->filter($isNearFull)->values(),
                'splitters' => $report['splitters']->filter($isNearFull)->values(),
                'cables' => $report['cables']->filter($isNearFull)->values(),
            ];
        }

        return view('livewire.network.capacity-report', $report);
    }
}
