<?php

namespace App\Livewire\Network;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.8.2 — full Monitoring page. Thin composition of two independently
 * reusable components (DeviceMonitoringList + DeviceTrafficGraph, see
 * their own docblocks) — this class itself holds no monitoring logic.
 * DeviceMonitoringList dispatches a `device-selected` browser event on row
 * click; DeviceTrafficGraph listens for it directly (Livewire's own event
 * bus, same `#[On(...)]` idiom already used by App\Livewire\Dashboard for
 * `widgets-updated`) — no prop-passing wiring needed here for that to
 * work, which is also exactly why both components can later be dropped
 * into the existing App\Enums\DashboardWidget registry (see
 * App\Livewire\Dashboard/WidgetSelector) without any redesign — deliberately
 * NOT done this sprint, see CLAUDE.md's "Dashboard Monitoring (v0.8.2)".
 */
class MonitoringIndex extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('monitoring.view');
    }

    public function render()
    {
        return view('livewire.network.monitoring-index');
    }
}
