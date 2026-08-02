<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Agent;
use Livewire\Component;

class TopAgents extends Component
{
    public function render()
    {
        $agents = Agent::withCount('referrals')
            ->orderByDesc('referrals_count')
            ->limit(5)
            ->get();

        return view('livewire.dashboard.widgets.top-agents', [
            'agents' => $agents,
        ]);
    }
}
