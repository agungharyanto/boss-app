<?php

namespace App\Livewire\Network;

use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * v0.8.2 — reusable per-device traffic graph. Independently mountable with
 * an explicit `deviceId`/`ifName` (the shape a future Dashboard placement
 * would use, one fixed device/interface), or mountable bare and driven by
 * the sibling DeviceMonitoringList's `device-selected` event (the shape
 * /monitoring uses — click a row, the graph below it updates) — see
 * CLAUDE.md's "Dashboard Monitoring (v0.8.2)" section.
 *
 * Data comes from LibreNmsService::getTrafficHistory(), which reads
 * LibreNMS's own RRD files directly via `rrdtool xport --json` — LibreNMS's
 * REST API has no raw time-series JSON endpoint in this version (its only
 * traffic-graph route renders a rendered SVG/PNG image, not data). Chart.js
 * (resources/js/app.js, `window.Chart`) renders it client-side — no other
 * chart library exists yet in this codebase, chosen per the sprint brief.
 */
class DeviceTrafficGraph extends Component
{
    use AuthorizesRequests;

    public ?int $deviceId = null;

    public ?string $selectedIfName = null;

    public int $rangeSeconds = 1800;

    /** @var array<int, array{port_id: int, if_name: string, if_oper_status: ?string}> */
    public array $availablePorts = [];

    /** @var array<int, array{timestamp: int, in_bytes_per_second: ?float, out_bytes_per_second: ?float}> */
    public array $series = [];

    public string $state = 'empty'; // empty|ok|no_sensor|unavailable

    public function mount(?int $deviceId = null, ?string $ifName = null, int $rangeSeconds = 1800): void
    {
        $this->authorize('monitoring.view');

        $this->rangeSeconds = $rangeSeconds;
        $this->deviceId = $deviceId;
        $this->selectedIfName = $ifName;

        if ($this->deviceId !== null) {
            $this->loadPorts();
            $this->loadSeries();
        }
    }

    #[On('device-selected')]
    public function changeDevice(?int $deviceId): void
    {
        $this->deviceId = $deviceId;
        $this->selectedIfName = null;
        $this->series = [];

        if ($deviceId === null) {
            $this->state = 'empty';
            $this->availablePorts = [];

            return;
        }

        $this->loadPorts();
        $this->loadSeries();
    }

    public function updatedSelectedIfName(): void
    {
        $this->loadSeries();
    }

    private function loadPorts(?LibreNmsService $service = null): void
    {
        $service ??= app(LibreNmsService::class);

        try {
            $this->availablePorts = $service->listPorts($this->deviceId);
        } catch (Throwable $e) {
            Log::warning('LibreNMS port list unavailable', ['exception' => $e->getMessage()]);
            $this->availablePorts = [];
            $this->state = 'unavailable';

            return;
        }

        if ($this->selectedIfName === null) {
            $preferred = collect($this->availablePorts)->firstWhere('if_oper_status', 'up')
                ?? $this->availablePorts[0] ?? null;

            $this->selectedIfName = $preferred['if_name'] ?? null;
        }
    }

    public function loadSeries(?LibreNmsService $service = null): void
    {
        if ($this->deviceId === null || $this->selectedIfName === null) {
            $this->series = [];
            $this->state = 'empty';

            return;
        }

        $service ??= app(LibreNmsService::class);

        try {
            $this->series = $service->getTrafficHistory($this->deviceId, $this->selectedIfName, $this->rangeSeconds);
            $this->state = 'ok';
        } catch (Throwable $e) {
            Log::warning('LibreNMS traffic history unavailable', ['exception' => $e->getMessage()]);
            $this->series = [];
            $this->state = 'unavailable';
        }

        // Chart.js's canvas lives inside a `wire:ignore` wrapper (see the
        // paired Blade view) — this dispatch is how it learns about fresh
        // data instead of a Livewire DOM morph, which would never reach it.
        $this->dispatch('traffic-series-updated', series: $this->series, ifName: $this->selectedIfName);
    }

    public function render()
    {
        return view('livewire.network.device-traffic-graph');
    }
}
