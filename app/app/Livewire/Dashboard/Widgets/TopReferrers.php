<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Referrer;
use Livewire\Component;

class TopReferrers extends Component
{
    public function render()
    {
        $referrers = Referrer::withCount('referrals')
            ->orderByDesc('referrals_count')
            ->limit(5)
            ->get();

        return view('livewire.dashboard.widgets.top-referrers', [
            'referrers' => $referrers,
        ]);
    }
}
