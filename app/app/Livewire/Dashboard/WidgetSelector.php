<?php

namespace App\Livewire\Dashboard;

use App\Enums\DashboardWidget;
use App\Services\DashboardWidgetService;
use Livewire\Component;

class WidgetSelector extends Component
{
    /** @var array<string, bool> */
    public array $selected = [];

    public function mount(DashboardWidgetService $service): void
    {
        $active = collect($service->activeWidgetValues(auth()->user()));

        foreach (DashboardWidget::cases() as $widget) {
            $this->selected[$widget->value] = $active->contains($widget->value);
        }
    }

    public function save(DashboardWidgetService $service): void
    {
        $activeWidgets = collect($this->selected)
            ->filter()
            ->keys()
            ->values()
            ->all();

        $service->update(auth()->user(), $activeWidgets);

        $this->dispatch('widgets-updated');
    }

    public function render()
    {
        return view('livewire.dashboard.widget-selector', [
            'widgets' => DashboardWidget::cases(),
        ]);
    }
}
