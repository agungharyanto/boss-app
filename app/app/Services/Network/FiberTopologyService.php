<?php

namespace App\Services\Network;

use App\Enums\FiberCoreStatus;
use App\Enums\FiberNodeType;
use App\Enums\OdpPortStatus;
use App\Models\FiberAccessory;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Odp;
use App\Models\Splitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * v0.16.0 — Core Network Infrastructure Management business logic
 * (BOSS-006: this stays out of any future Controller/Livewire component).
 * Backend-only this Langkah — no route/UI wires into this yet.
 */
class FiberTopologyService
{
    public function __construct(
        private readonly FiberColorService $colorService,
    ) {}

    /**
     * Creates a FiberCable and auto-generates its FiberCore rows
     * (tube_color/core_color derived from FiberColorService's TIA/EIA-598-C
     * cycle — there's no override input here, this is the initial
     * bulk-creation path; a later per-core edit overriding a color is a
     * separate, Langkah 3+ concern).
     *
     * total_cores must be even (rejected otherwise — an odd core count
     * cannot form whole tube/core coordinate pairs cleanly and doesn't
     * correspond to any real fiber cable SKU) and must equal
     * tube_count * cores_per_tube exactly, which is itself also checked
     * for evenness as an additional sanity check per the sprint brief —
     * both conditions are needed for the tube/core-in-tube coordinate walk
     * below to produce exactly total_cores well-defined rows.
     *
     * @param  array{tenant_id?: int, from_type: class-string, from_id: int, to_type: class-string, to_id: int, total_cores: int, tube_count: int, cores_per_tube: int}  $data
     */
    public function createCable(array $data): FiberCable
    {
        $totalCores = (int) $data['total_cores'];
        $tubeCount = (int) $data['tube_count'];
        $coresPerTube = (int) $data['cores_per_tube'];
        $tubeTimesCore = $tubeCount * $coresPerTube;

        if ($totalCores % 2 !== 0) {
            throw new InvalidArgumentException('Jumlah core harus genap.');
        }

        if ($tubeTimesCore % 2 !== 0) {
            throw new InvalidArgumentException('Jumlah tube dikali core per tube harus genap juga.');
        }

        if ($tubeTimesCore !== $totalCores) {
            throw new InvalidArgumentException('Jumlah tube dikali core per tube harus sama dengan jumlah core total.');
        }

        $cable = FiberCable::create($data);

        for ($tubeNumber = 1; $tubeNumber <= $tubeCount; $tubeNumber++) {
            $tubeColor = $this->colorService->resolveColor($tubeNumber)['name'];

            for ($coreNumber = 1; $coreNumber <= $coresPerTube; $coreNumber++) {
                FiberCore::create([
                    'fiber_cable_id' => $cable->id,
                    'tube_number' => $tubeNumber,
                    'core_number_in_tube' => $coreNumber,
                    'tube_color' => $tubeColor,
                    'core_color' => $this->colorService->resolveColor($coreNumber)['name'],
                ]);
            }
        }

        return $cable->refresh();
    }

    /**
     * Whether loss_in_db/loss_out_db are required for $target — called
     * from a FormRequest's own withValidator(), never from a model
     * lifecycle event/observer (see docs/ROADMAP.md's v0.16.0 "Koreksi
     * Langkah 0 poin 2" for why: these columns stay unconstrained at the
     * DB/Model level, the requirement only ever lives in the validation
     * layer). true for a FiberNode of type Odc, or for ANY Odp (Odp has
     * no node_type of its own — it's always a splitting point) — false
     * for OTB/Closure, which aren't splitting points.
     *
     * $target doesn't need to be persisted — a FormRequest building this
     * from raw input (e.g. `new FiberNode(['node_type' => ...])`) before
     * the record exists works fine, since only node_type/instanceof is
     * inspected here.
     */
    public function isLossRequired(FiberNode|Odp $target): bool
    {
        if ($target instanceof Odp) {
            return true;
        }

        return $target->node_type === FiberNodeType::Odc;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createNode(array $data): FiberNode
    {
        return FiberNode::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateNode(FiberNode $node, array $data): FiberNode
    {
        $node->update($data);

        return $node->fresh();
    }

    /**
     * Odp's own v0.16.0-only fields (parent link + loss) — deliberately
     * separate from StoreOdpRequest/UpdateOdpRequest (v0.5.0's own
     * registration flow, which this Langkah does NOT touch at all). Used
     * by the new App\Livewire\Installation\OdpEdit page.
     *
     * @param  array{parent_type?: ?string, parent_id?: ?int, loss_in_db?: ?float, loss_out_db?: ?float}  $data
     */
    public function updateOdpTopologyFields(Odp $odp, array $data): Odp
    {
        $odp->update($data);

        return $odp->fresh();
    }

    public function deleteNode(FiberNode $node): void
    {
        $node->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSplitter(array $data): Splitter
    {
        return Splitter::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccessory(array $data): FiberAccessory
    {
        return FiberAccessory::create($data);
    }

    /**
     * Writes lat/long directly onto an already-persisted FiberNode/Odp —
     * used by the reusable GPS+Photo Livewire widget (Langkah 3), which
     * always operates against a real, already-saved owner (see that
     * component's own docblock for why "brand new, unsaved node" never
     * reaches this method).
     */
    public function updateCoordinates(FiberNode|Odp $target, ?float $latitude, ?float $longitude): void
    {
        $target->update(['latitude' => $latitude, 'longitude' => $longitude]);
    }

    /**
     * Stored on the 'local' disk (private, never publicly served) — same
     * posture as WorkOrderPhotoService (v0.5.0). Unlike WorkOrderPhoto,
     * FiberNodePhoto has no per-type uniqueness — every call adds a new
     * row, never replaces an existing one (a topology point can have any
     * number of photos).
     */
    public function addPhoto(FiberNode|Odp $owner, UploadedFile $file, ?string $caption = null): FiberNodePhoto
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $storedPath = Storage::disk('local')->putFile(
            'fiber-node-photos/'.get_class($owner).'/'.$owner->id,
            $file
        );

        return FiberNodePhoto::create([
            'owner_type' => get_class($owner),
            'owner_id' => $owner->id,
            'photo_path' => $storedPath,
            'caption' => $caption,
            'taken_at' => now(),
        ]);
    }

    public function deletePhoto(FiberNodePhoto $photo): void
    {
        Storage::disk('local')->delete($photo->photo_path);
        $photo->delete();
    }

    /**
     * A single, normalized list combining fiber_nodes (OTB/Closure/ODC)
     * and odps (ODP) — the union query lives here, not in
     * App\Livewire\Network\FiberNodeIndex, per BOSS-006. Both source
     * tables' own global scopes (BelongsToTenant/BelongsToResellerScope)
     * apply normally since this still goes through each model's own
     * Eloquent query builder before the union.
     *
     * $nodeTypeFilter accepts 'otb'/'closure'/'odc'/'odp' (the fourth
     * value isn't a real FiberNodeType case — it's the pseudo-type this
     * method uses to mean "only odps"), or null for no filter.
     *
     * No pagination — a plain ordered Collection. This fleet is expected
     * to stay small enough (hundreds, not tens of thousands, of topology
     * points) for Langkah 3's "CRUD dasar" scope; revisit if that stops
     * being true.
     *
     * @return Collection<int, object>
     */
    public function listTopologyPoints(?string $nodeTypeFilter = null, ?string $search = null): Collection
    {
        $nodesQuery = FiberNode::query()
            ->select([
                'id',
                DB::raw("'fiber_node' as source"),
                'node_type',
                DB::raw('COALESCE(local_label, node_type) as label'),
                'latitude',
                'longitude',
                'created_at',
            ]);

        $odpsQuery = Odp::query()
            ->select([
                'id',
                DB::raw("'odp' as source"),
                DB::raw("'odp' as node_type"),
                DB::raw('name as label'),
                'latitude',
                'longitude',
                'created_at',
            ]);

        if ($search !== null && $search !== '') {
            $nodesQuery->where('local_label', 'like', "%{$search}%");
            $odpsQuery->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($nodeTypeFilter === 'odp') {
            $nodesQuery->whereRaw('1 = 0');
        } elseif ($nodeTypeFilter !== null) {
            $nodesQuery->where('node_type', $nodeTypeFilter);
            $odpsQuery->whereRaw('1 = 0');
        }

        // toBase() BEFORE union()/get() is load-bearing, not cosmetic —
        // without it, Eloquent hydrates every unioned row as a FiberNode
        // model (since the union was invoked on FiberNode's own Builder)
        // and then FiberNode's own casts() tries to cast an ODP row's
        // literal 'odp' string into the FiberNodeType enum, which isn't a
        // valid case and throws. toBase() converts both sides to plain
        // query builders first (global scopes are already baked into the
        // WHERE clauses by this point), so get() returns plain stdClass
        // rows with zero Eloquent casting involved.
        return $nodesQuery->toBase()->union($odpsQuery->toBase())->orderByDesc('created_at')->get();
    }

    /**
     * v0.16.0 Langkah 4 — everything App\Livewire\Network\FiberNodeDetail
     * needs to render one splice diagram: cables terminating AT this node
     * ("incoming"), cables originating FROM this node toward children
     * ("outgoing" — the diagram's own notion of "child" is the cable
     * graph's `to` endpoint, per the sprint brief, NOT the separate
     * parent_type/parent_id administrative link), splitters mounted at
     * this node, and every accessory reachable from any of those cables/
     * splitters (for the expected-vs-measured loss comparison list).
     *
     * @return array{
     *     target: FiberNode|Odp,
     *     incoming_cables: Collection<int, FiberCable>,
     *     outgoing_cables: Collection<int, FiberCable>,
     *     splitters: Collection<int, Splitter>,
     *     children: list<array{type: class-string, id: int, label: string, cable_id: int}>,
     *     accessories: Collection<int, FiberAccessory>,
     * }
     */
    public function spliceDiagramData(FiberNode|Odp $node): array
    {
        $incoming = $node->cablesAsTo()->with('cores')->get();
        $outgoing = $node->cablesAsFrom()->with('cores')->get();
        $splitters = $node->splitters()->with('accessories')->get();

        $children = $outgoing->map(fn (FiberCable $cable) => [
            'type' => $cable->to_type,
            'id' => $cable->to_id,
            'label' => $this->labelForMorph($cable->to_type, $cable->to_id),
            'cable_id' => $cable->id,
        ])->values()->all();

        $cableIds = $incoming->pluck('id')->merge($outgoing->pluck('id'))->unique();
        $accessories = FiberAccessory::query()
            ->whereIn('fiber_cable_id', $cableIds)
            ->orWhereIn('splitter_id', $splitters->pluck('id'))
            ->get();

        return [
            'target' => $node,
            'incoming_cables' => $incoming,
            'outgoing_cables' => $outgoing,
            'splitters' => $splitters,
            'children' => $children,
            'accessories' => $accessories,
        ];
    }

    private function labelForMorph(string $type, int $id): string
    {
        if ($type === FiberNode::class) {
            $node = FiberNode::find($id);

            return $node?->local_label ?? $node?->node_type?->label() ?? "Titik #{$id}";
        }

        $odp = Odp::find($id);

        return $odp !== null ? "{$odp->code} - {$odp->name}" : "ODP #{$id}";
    }

    /**
     * v0.16.0 Langkah 4 — capacity per category for
     * App\Livewire\Network\CapacityReport, filtered in PHP (not 3
     * different heterogeneous SQL search queries) since this fleet is the
     * same "hundreds, not tens of thousands" scale already assumed by
     * listTopologyPoints() (Langkah 3).
     *
     * Splitter "used" is counted via FiberAccessory rows attached to it
     * (each represents one terminated splitter output leg) — NOT via
     * fiber_cables pointing at the splitter, because fiber_cables.from_type/
     * to_type is validated (StoreFiberCableRequest, Langkah 3) to only
     * ever be FiberNode or Odp, never Splitter; this is the schema-
     * faithful equivalent of "splitter output ports in use".
     *
     * @return array{odps: Collection<int, object>, splitters: Collection<int, object>, cables: Collection<int, object>}
     */
    public function capacityReport(?string $search = null): array
    {
        $odps = Odp::query()
            ->withCount(['ports as used_ports_count' => fn ($query) => $query->where('status', OdpPortStatus::Used->value)])
            ->get()
            ->map(fn (Odp $odp) => (object) [
                'id' => $odp->id,
                'category' => 'odp',
                'label' => "{$odp->code} - {$odp->name}",
                'used' => $odp->used_ports_count,
                'total' => $odp->total_ports,
                'percent' => $odp->total_ports > 0 ? (int) round($odp->used_ports_count / $odp->total_ports * 100) : 0,
            ]);

        $splitters = Splitter::query()
            ->tenantScoped()
            ->withCount('accessories')
            ->get()
            ->map(function (Splitter $splitter) {
                $total = $this->parseRatioOutputs($splitter->ratio);
                $used = $splitter->accessories_count;

                return (object) [
                    'id' => $splitter->id,
                    'category' => 'splitter',
                    'label' => 'Splitter '.$splitter->ratio.($splitter->model !== null ? " ({$splitter->model})" : ''),
                    'used' => $used,
                    'total' => $total,
                    'percent' => $total !== null && $total > 0 ? (int) round(min($used, $total) / $total * 100) : null,
                ];
            });

        $cables = FiberCable::query()
            ->withCount(['cores as used_cores_count' => fn ($query) => $query->where('status', FiberCoreStatus::Used->value)])
            ->get()
            ->map(fn (FiberCable $cable) => (object) [
                'id' => $cable->id,
                'category' => 'cable',
                'label' => "Kabel #{$cable->id} ({$cable->total_cores} core)",
                'used' => $cable->used_cores_count,
                'total' => $cable->total_cores,
                'percent' => $cable->total_cores > 0 ? (int) round($cable->used_cores_count / $cable->total_cores * 100) : 0,
            ]);

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $filter = fn (object $row): bool => str_contains(mb_strtolower($row->label), $needle);
            $odps = $odps->filter($filter)->values();
            $splitters = $splitters->filter($filter)->values();
            $cables = $cables->filter($filter)->values();
        }

        return ['odps' => $odps, 'splitters' => $splitters, 'cables' => $cables];
    }

    private function parseRatioOutputs(string $ratio): ?int
    {
        $parts = explode(':', $ratio);

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }
}
