<?php

namespace App\Livewire;

use App\Services\DashboardWidgetService;
use Livewire\Attributes\On;
use Livewire\Component;

class Dashboard extends Component
{
    #[On('widgets-updated')]
    public function refreshWidgets(): void
    {
        // Re-rendering is enough — activeWidgets() re-reads the fresh preference.
    }

    public function render(DashboardWidgetService $service)
    {
        return view('livewire.dashboard', [
            'activeWidgets' => $service->activeWidgets(auth()->user()),
        ]);
    }
}
