<?php

namespace App\Livewire;

use App\Enums\DashboardWidget;
use Livewire\Attributes\On;
use Livewire\Component;

class Dashboard extends Component
{
    #[On('widgets-updated')]
    public function refreshWidgets(): void
    {
        // Re-rendering is enough — activeWidgets() re-reads the fresh preference.
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'activeWidgets' => $this->activeWidgets(),
        ]);
    }

    /**
     * @return list<DashboardWidget>
     */
    private function activeWidgets(): array
    {
        $saved = auth()->user()->preference?->dashboard_widgets;

        if ($saved === null) {
            return DashboardWidget::defaults();
        }

        return collect($saved)
            ->map(fn (string $value) => DashboardWidget::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }
}
