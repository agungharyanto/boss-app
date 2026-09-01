<?php

namespace App\Livewire\Network;

use App\Models\Customer;
use App\Models\Odp;
use App\Models\SalesRouteNote;
use App\Services\Installation\OdpLocatorService;
use App\Services\Network\FiberTopologyService;
use App\Services\Network\RoutingService;
use Livewire\Component;

/**
 * v0.16.0 Langkah 11 — "Cek Jalur ke ODP". A sales rep drops the
 * prospect's location (their own GPS or a manual pin), picks the target
 * ODP from the nearest few candidates, and gets the real road route(s)
 * OSRM finds — shortest flagged "Rekomendasi", the rest "Alternatif
 * B/C/…". Each option can carry a free-text note and be saved to
 * sales_route_notes. Pure reference — nothing here touches billing.
 *
 * Gated on network_infrastructure.view/.manage (same as the rest of the
 * fiber module). Widening this to sales_internal/sales_freelance is a
 * separate RBAC decision, not assumed here.
 */
class OdpRouteCheck extends Component
{
    public ?string $latitude = null;

    public ?string $longitude = null;

    public string $prospectName = '';

    public string $prospectAddress = '';

    public ?int $customerId = null;

    public string $customerSearch = '';

    public ?int $targetOdpId = null;

    /** @var list<array<string, mixed>> route options from RoutingService */
    public array $routeOptions = [];

    /** @var array<int, string> optionIndex => free-text note */
    public array $routeNotes = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
    }

    public function updatedLatitude(): void
    {
        $this->pushCandidates();
    }

    public function updatedLongitude(): void
    {
        $this->pushCandidates();
    }

    private function pushCandidates(): void
    {
        if (! is_numeric($this->latitude) || ! is_numeric($this->longitude)) {
            return;
        }

        $this->dispatch('candidates-updated', candidates: $this->candidatePayload(
            app(OdpLocatorService::class),
            app(FiberTopologyService::class),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidatePayload(OdpLocatorService $locator, FiberTopologyService $topology): array
    {
        if (! is_numeric($this->latitude) || ! is_numeric($this->longitude)) {
            return [];
        }

        return array_map(function (array $c) use ($topology) {
            $percent = $c['total_ports'] > 0 ? (int) round($c['used_ports'] / $c['total_ports'] * 100) : null;
            $zone = $topology->capacityZone($percent);

            return $c + ['percent' => $percent, 'zone_label' => $zone['label'], 'zone_color' => $zone['color']];
        }, $locator->nearestCandidates((float) $this->latitude, (float) $this->longitude, 8));
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::find($customerId);

        if ($customer === null) {
            return;
        }

        $this->customerId = $customer->id;
        $this->prospectName = $customer->name;
        $this->prospectAddress = (string) $customer->address;
        $this->customerSearch = '';

        if ($customer->latitude !== null && $customer->longitude !== null) {
            $this->latitude = (string) $customer->latitude;
            $this->longitude = (string) $customer->longitude;
        }
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
    }

    public function calculateRoutes(OdpLocatorService $locator, RoutingService $routing): void
    {
        abort_unless($this->canView(), 403);

        $this->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'targetOdpId' => ['required', 'integer'],
        ], [], [
            'latitude' => 'Lokasi calon pelanggan',
            'longitude' => 'Lokasi calon pelanggan',
            'targetOdpId' => 'ODP tujuan',
        ]);

        $candidateIds = collect($locator->nearestCandidates((float) $this->latitude, (float) $this->longitude, 8))
            ->pluck('odp_id')->all();

        if (! in_array($this->targetOdpId, $candidateIds, true)) {
            $this->addError('targetOdpId', 'ODP tujuan bukan salah satu kandidat terdekat — pilih ulang.');

            return;
        }

        $odp = Odp::findOrFail($this->targetOdpId);

        $this->routeOptions = $routing->getRouteOptions(
            (float) $this->latitude,
            (float) $this->longitude,
            (float) $odp->latitude,
            (float) $odp->longitude,
        );
        $this->routeNotes = [];
        $this->statusMessage = '';

        $this->dispatch('routes-updated', payload: [
            'from' => [(float) $this->latitude, (float) $this->longitude],
            'to' => [(float) $odp->latitude, (float) $odp->longitude],
            'options' => $this->routeOptions,
        ]);
    }

    public function saveRoute(int $index): void
    {
        abort_unless($this->canView(), 403);

        if (! isset($this->routeOptions[$index])) {
            return;
        }

        $this->validate([
            'prospectName' => ['required_without:customerId', 'nullable', 'string', 'max:255'],
            'targetOdpId' => ['required', 'integer'],
        ], [], ['prospectName' => 'Nama calon pelanggan']);

        $option = $this->routeOptions[$index];

        SalesRouteNote::create([
            'customer_id' => $this->customerId,
            'prospect_name' => $this->customerId === null ? ($this->prospectName ?: null) : null,
            'prospect_address' => $this->customerId === null ? ($this->prospectAddress ?: null) : null,
            'from_latitude' => (float) $this->latitude,
            'from_longitude' => (float) $this->longitude,
            'target_odp_id' => $this->targetOdpId,
            'route_label' => $option['label'] ?? null,
            'route_geometry' => $option['geometry'],
            'distance_meters' => (int) round($option['distance_meters']),
            'is_straight_line_estimate' => (bool) ($option['is_fallback'] ?? false),
            'note' => $this->routeNotes[$index] ?? null,
            'created_by' => auth()->id(),
        ]);

        $this->statusMessage = 'Catatan jalur "'.($option['label'] ?? 'rute').'" disimpan.';
    }

    private function canView(): bool
    {
        return auth()->user()->can('network_infrastructure.view')
            || auth()->user()->can('network_infrastructure.manage');
    }

    public function render(OdpLocatorService $locator, FiberTopologyService $topology)
    {
        $candidates = $this->candidatePayload($locator, $topology);

        $customerMatches = trim($this->customerSearch) === '' ? [] : Customer::query()
            ->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->customerSearch).'%')
                ->orWhere('phone_number', 'like', '%'.trim($this->customerSearch).'%'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'address', 'phone_number']);

        return view('livewire.network.odp-route-check', [
            'candidates' => $candidates,
            'customerMatches' => $customerMatches,
            'canManage' => auth()->user()->can('network_infrastructure.manage'),
        ]);
    }
}
