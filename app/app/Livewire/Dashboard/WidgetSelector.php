<?php

namespace App\Livewire\Dashboard;

use App\Enums\DashboardWidget;
use Livewire\Component;

class WidgetSelector extends Component
{
    /** @var array<string, bool> */
    public array $selected = [];

    public function mount(): void
    {
        $saved = auth()->user()->preference?->dashboard_widgets;
        $active = $saved !== null
            ? collect($saved)
            : collect(DashboardWidget::defaults())->map(fn (DashboardWidget $w) => $w->value);

        foreach (DashboardWidget::cases() as $widget) {
            $this->selected[$widget->value] = $active->contains($widget->value);
        }
    }

    public function save(): void
    {
        $activeWidgets = collect($this->selected)
            ->filter()
            ->keys()
            ->values()
            ->all();

        auth()->user()->preference()->updateOrCreate([], [
            'dashboard_widgets' => $activeWidgets,
        ]);

        $this->dispatch('widgets-updated');
    }

    public function render()
    {
        return view('livewire.dashboard.widget-selector', [
            'widgets' => DashboardWidget::cases(),
        ]);
    }
}
