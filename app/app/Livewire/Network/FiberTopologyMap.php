<?php

namespace App\Livewire\Network;

use App\Models\FiberCable;
use App\Services\Network\FiberTopologyService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * v0.16.0 Langkah 8/9 — "Peta Topologi". A Leaflet map (OSM + Esri
 * satellite base layers) of every coordinate-bearing fiber_node / odp as
 * a marker, with NO cable lines drawn by default.
 *
 * A line appears only when a CABLE is selected — either via the ?cable=
 * query param (the "Lihat di peta" link on FiberNodeDetail's "Koneksi
 * Core" table, one per cable) or the on-page cable picker. The unit of
 * selection everywhere in this feature is the CABLE, never an individual
 * core: one cable is one physical route, and its many cores share it.
 * Line colour is the neutral FiberTopologyService::CABLE_LINE_COLOR, not
 * a per-core colour.
 *
 * Several cables can be shown at once for comparison. Waypoints for a
 * cable's route are editable on the map and saved via saveRoute(), which
 * replaces every waypoint of that cable wholesale.
 *
 * The map lives inside wire:ignore (Leaflet owns that subtree), so line
 * data reaches it via a dispatched `topology-lines-updated` browser
 * event after every selection change.
 */
class FiberTopologyMap extends Component
{
    /**
     * v0.16.0 Langkah 12 — the multi-select cable checklist. Any number
     * of cables can be shown at once; unchecking one hides only that
     * cable. Every cable line still renders in the neutral
     * CABLE_LINE_COLOR (not a per-cable colour) so a crowded map stays
     * readable.
     *
     * @var list<int>
     */
    public array $selectedCableIds = [];

    public bool $showExportPanel = false;

    /**
     * Categories included in the next KMZ export — the checklist shown
     * before "Download KMZ". Defaults to every category (an explicit
     * export usually wants everything; user unchecks what they don't).
     *
     * @var list<string>
     */
    public array $exportCategories = FiberTopologyService::MAP_CATEGORIES;

    /**
     * ?cable=<id> deep-link from FiberNodeDetail's "Lihat di peta" link.
     * Only used at mount to seed selectedCableIds; not kept in sync after.
     */
    #[Url(as: 'cable')]
    public ?int $cable = null;

    public function mount(): void
    {
        abort_unless($this->canView(), 403);

        if ($this->cable !== null && FiberCable::query()->whereKey($this->cable)->exists()) {
            $this->selectedCableIds = [$this->cable];
        }
    }

    /**
     * Checklist changed (a box checked/unchecked) — normalise the array
     * to ints and redraw. (A lifecycle hook can't have a service
     * method-injected the way an action can, so resolve it here.)
     */
    public function updatedSelectedCableIds(): void
    {
        $this->selectedCableIds = array_values(array_unique(array_map('intval', $this->selectedCableIds)));
        $this->pushLines(app(FiberTopologyService::class));
    }

    public function showCable(int $cableId, FiberTopologyService $service): void
    {
        abort_unless($this->canView(), 403);

        if (! FiberCable::query()->whereKey($cableId)->exists()) {
            return;
        }

        if (! in_array($cableId, $this->selectedCableIds, true)) {
            $this->selectedCableIds[] = $cableId;
        }

        $this->pushLines($service);
    }

    public function hideCable(int $cableId, FiberTopologyService $service): void
    {
        $this->selectedCableIds = array_values(array_filter(
            $this->selectedCableIds,
            fn (int $id) => $id !== $cableId,
        ));

        $this->pushLines($service);
    }

    /**
     * "Pilih Semua" toggle — select every mappable cable, or clear if
     * they're all already selected.
     */
    public function toggleAllCables(FiberTopologyService $service): void
    {
        abort_unless($this->canView(), 403);

        $allIds = array_column($service->mappableCableOptions(), 'cable_id');

        $this->selectedCableIds = count(array_diff($allIds, $this->selectedCableIds)) === 0
            ? []
            : $allIds;

        $this->pushLines($service);
    }

    /**
     * Replace every waypoint of $cableId with the given ordered points
     * (each {lat,lng}). An empty list clears the route to a straight line.
     *
     * @param  array<int, array{lat: mixed, lng: mixed}>  $points
     */
    public function saveRoute(int $cableId, array $points, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $cable = FiberCable::findOrFail($cableId);

        $clean = [];
        foreach ($points as $point) {
            $lat = filter_var($point['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lng = filter_var($point['lng'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($lat === false || $lng === false) {
                continue;
            }

            $clean[] = ['lat' => $lat, 'lng' => $lng];
        }

        $service->replaceCableWaypoints($cable, $clean);

        session()->flash('map-status', 'Rute kabel #'.$cableId.' disimpan ('.count($clean).' titik).');
        $this->pushLines($service);
    }

    /**
     * v0.16.0 Langkah 9 — download the whole topology as a .kmz
     * (Placemarks for every node/odp, LineStrings for every cable with
     * complete coordinates). ZipArchive/DOMDocument, no new package.
     */
    public function exportKmz(FiberTopologyService $service)
    {
        abort_unless($this->canView(), 403);

        $bytes = $service->buildTopologyKmz($this->exportCategories);

        return response()->streamDownload(
            function () use ($bytes) {
                echo $bytes;
            },
            'topologi-fiber-'.now()->format('Ymd-His').'.kmz',
            ['Content-Type' => 'application/vnd.google-earth.kmz'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function currentLines(FiberTopologyService $service): array
    {
        $lines = [];

        foreach ($this->selectedCableIds as $cableId) {
            $cable = FiberCable::with('waypoints')->find($cableId);

            if ($cable === null) {
                continue;
            }

            $line = $service->cableLineData($cable);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function pushLines(FiberTopologyService $service): void
    {
        $this->dispatch('topology-lines-updated', lines: $this->currentLines($service));
    }

    private function canView(): bool
    {
        return auth()->user()->can('network_infrastructure.view')
            || auth()->user()->can('network_infrastructure.manage');
    }

    public function render(FiberTopologyService $service)
    {
        $cableOptions = $service->mappableCableOptions();
        $allCableIds = array_column($cableOptions, 'cable_id');

        return view('livewire.network.fiber-topology-map', [
            'markers' => $service->topologyMapMarkers(),
            'customers' => $service->topologyMapCustomers(),
            'lines' => $this->currentLines($service),
            'cableOptions' => $cableOptions,
            'allCablesSelected' => $allCableIds !== [] && count(array_diff($allCableIds, $this->selectedCableIds)) === 0,
            'canManage' => auth()->user()->can('network_infrastructure.manage'),
            'categories' => FiberTopologyService::MAP_CATEGORIES,
            'defaultLayers' => FiberTopologyService::DEFAULT_MAP_LAYERS,
        ]);
    }
}
