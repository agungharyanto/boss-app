<?php

namespace App\Services\Network;

use App\Enums\FiberAccessoryType;
use App\Enums\FiberCoreStatus;
use App\Enums\FiberNodeType;
use App\Enums\OdpPortStatus;
use App\Models\Customer;
use App\Models\FiberAccessory;
use App\Models\FiberCable;
use App\Models\FiberCableWaypoint;
use App\Models\FiberCore;
use App\Models\FiberCorePortLog;
use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Odp;
use App\Models\OltDevice;
use App\Models\Splitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
     * v0.16.0 Langkah 5 — create a FiberNode and, IN THE SAME
     * TRANSACTION, attach any freshly-picked photos and (for an ODC) a
     * splitter. This is what lets FiberNodeForm's own create mode carry
     * GPS + photos + splitter without ever handing a Livewire
     * TemporaryUploadedFile between two component instances (the reason
     * GpsPhotoCapture was edit-only until now — see its docblock).
     *
     * A rolled-back create leaves no fiber_nodes/fiber_node_photos/
     * splitters rows; a stored image file for a row that then rolled back
     * is a rare orphan on disk, the same trade-off addPhoto() already
     * carries everywhere else it's called.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<int, UploadedFile>  $photos
     * @param  array{ratio?: ?string, model?: ?string}|null  $splitter
     */
    public function createNodeWithAttachments(array $data, iterable $photos = [], ?array $splitter = null): FiberNode
    {
        return DB::transaction(function () use ($data, $photos, $splitter) {
            $node = FiberNode::create($data);

            foreach ($photos as $photo) {
                $this->addPhoto($node, $photo);
            }

            $this->attachSplitter($node, $splitter);

            return $node;
        });
    }

    /**
     * No-op when $splitter is null or its ratio is blank — callers pass
     * the raw form values straight through and let this decide.
     *
     * @param  array{ratio?: ?string, model?: ?string}|null  $splitter
     */
    public function attachSplitter(FiberNode|Odp $owner, ?array $splitter): ?Splitter
    {
        $ratio = trim((string) ($splitter['ratio'] ?? ''));

        if ($ratio === '') {
            return null;
        }

        $model = trim((string) ($splitter['model'] ?? ''));

        return Splitter::create([
            'owner_type' => $owner::class,
            'owner_id' => $owner->id,
            'ratio' => $ratio,
            'model' => $model !== '' ? $model : null,
        ]);
    }

    public function deleteSplitter(Splitter $splitter): void
    {
        $splitter->delete();
    }

    /**
     * v0.16.0 Langkah 5 — per-core manual colour override from
     * FiberCableForm's post-generate review table. A blank string clears
     * the override back to null (the column is nullable; a read-time
     * consumer falls back to the TIA/EIA cycle name it was seeded with,
     * see FiberColorService::hexForName()'s docblock).
     */
    public function overrideCoreColor(FiberCore $core, ?string $tubeColor, ?string $coreColor): FiberCore
    {
        $core->update([
            'tube_color' => ($tubeColor !== null && trim($tubeColor) !== '') ? trim($tubeColor) : null,
            'core_color' => ($coreColor !== null && trim($coreColor) !== '') ? trim($coreColor) : null,
        ]);

        return $core->refresh();
    }

    /**
     * v0.16.0 Langkah 5 — coordinate-bearing topology points (both tables)
     * for a form's reference map, as plain arrays. Tenant/reseller scoped
     * (each model's own global scope applies). $exclude is the point being
     * edited itself, so its own marker isn't drawn twice.
     *
     * @return list<array{type: string, id: int, label: string, latitude: float, longitude: float}>
     */
    public function mapReferencePoints(FiberNode|Odp|null $exclude = null): array
    {
        $points = [];

        $nodes = FiberNode::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($exclude instanceof FiberNode, fn ($q) => $q->whereKeyNot($exclude->id))
            ->get(['id', 'local_label', 'node_type', 'latitude', 'longitude']);

        foreach ($nodes as $node) {
            $points[] = [
                'type' => 'fiber_node',
                'id' => $node->id,
                'label' => $node->local_label ?? $node->node_type->label(),
                'latitude' => (float) $node->latitude,
                'longitude' => (float) $node->longitude,
            ];
        }

        $odps = Odp::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($exclude instanceof Odp, fn ($q) => $q->whereKeyNot($exclude->id))
            ->get(['id', 'code', 'name', 'latitude', 'longitude']);

        foreach ($odps as $odp) {
            $points[] = [
                'type' => 'odp',
                'id' => $odp->id,
                'label' => "{$odp->code} - {$odp->name}",
                'latitude' => (float) $odp->latitude,
                'longitude' => (float) $odp->longitude,
            ];
        }

        return $points;
    }

    /**
     * v0.16.0 Langkah 8/9 — neutral colour every cable line renders in on
     * the "Peta Topologi" map. Deliberately NOT a per-core colour: one
     * cable carries many cores of different colours, so no single core
     * colour is representative of the cable's physical route. Core colour
     * stays a table/badge-only concern (FiberColorService).
     */
    public const CABLE_LINE_COLOR = '#1E3A8A'; // biru tua (blue-900)

    /**
     * v0.16.0 Langkah 10 — the layer/checklist categories used both by the
     * "Peta Topologi" map's layer control and the KMZ export checklist.
     * 'cable' = cable LineStrings; the other five = marker categories.
     */
    public const MAP_CATEGORIES = ['cable', 'otb', 'closure', 'odc', 'odp', 'customer'];

    /**
     * v0.16.0 Langkah 10 — categories ON by default when the map first
     * loads: every fiber node type, but NOT cable lines or customers
     * (same "don't render everything on open" principle as Langkah 8/9's
     * no-default-lines rule).
     */
    public const DEFAULT_MAP_LAYERS = ['otb', 'closure', 'odc', 'odp'];

    /**
     * v0.16.0 Langkah 8 — every coordinate-bearing topology point for the
     * "Peta Topologi" map, as plain arrays (markers only — no cable lines,
     * those are drawn on-demand per selected cable, see cableLineData()).
     *
     * @return list<array{type: string, node_type: ?string, id: int, label: string, latitude: float, longitude: float, capacity?: null|array{used: int, total: int, percent: ?int, zone_label: string, zone_color: string}}>
     */
    public function topologyMapMarkers(): array
    {
        $markers = [];

        $nodes = FiberNode::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'local_label', 'node_type', 'latitude', 'longitude']);

        foreach ($nodes as $node) {
            $markers[] = [
                'type' => 'fiber_node',
                'node_type' => $node->node_type->value,
                'id' => $node->id,
                'label' => ($node->local_label ?? $node->node_type->label()).' ('.$node->node_type->label().')',
                'latitude' => (float) $node->latitude,
                'longitude' => (float) $node->longitude,
            ];
        }

        $odps = Odp::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'code', 'name', 'latitude', 'longitude']);

        $capacities = $this->odpCapacities();

        foreach ($odps as $odp) {
            $cap = $capacities->get($odp->id);

            $markers[] = [
                'type' => 'odp',
                'node_type' => 'odp',
                'id' => $odp->id,
                'label' => "{$odp->code} - {$odp->name} (ODP)",
                'latitude' => (float) $odp->latitude,
                'longitude' => (float) $odp->longitude,
                // v0.16.0 Langkah 11 Bagian E — "X/Y port terpakai" +
                // traffic-light badge in the map popup, same figure/zone
                // as the Capacity Report.
                'capacity' => $cap === null ? null : [
                    'used' => $cap['used'],
                    'total' => $cap['total'],
                    'percent' => $cap['percent'],
                    'zone_label' => $cap['zone_label'],
                    'zone_color' => $cap['zone_color'],
                ],
            ];
        }

        return $markers;
    }

    /**
     * v0.16.0 Langkah 10 — coordinate-bearing customers for the "Pelanggan"
     * map layer. Tenant/reseller scoped by Customer's own global scopes.
     * The same `customers.latitude`/`longitude` columns OdpLocatorService's
     * Haversine query reads — no new coordinate source. Most customers have
     * no coordinates yet (a later geocode/import step fills them), so this
     * routinely returns an empty list, which the UI must handle.
     *
     * @return list<array{id: int, name: string, address: ?string, status: string, latitude: float, longitude: float}>
     */
    public function topologyMapCustomers(): array
    {
        return Customer::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'status', 'latitude', 'longitude'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'address' => $c->address,
                'status' => $c->status->value,
                'latitude' => (float) $c->latitude,
                'longitude' => (float) $c->longitude,
            ])
            ->all();
    }

    /**
     * v0.16.0 Langkah 10 — normalise a caller-supplied category list down
     * to the known MAP_CATEGORIES (drops anything unrecognised). An empty
     * result means "nothing selected" — a valid state.
     *
     * @param  array<int, mixed>  $categories
     * @return list<string>
     */
    public function normaliseCategories(array $categories): array
    {
        return array_values(array_intersect(self::MAP_CATEGORIES, array_map('strval', $categories)));
    }

    /**
     * v0.16.0 Langkah 8/9 — every cable touching $node (incoming AND
     * outgoing), each with its full core list, for the FiberNodeDetail
     * "Koneksi Core" table. The "Lihat di peta" link is per-CABLE (one
     * physical route per cable), not per-core — `mappable` and
     * `cable_id`/`from_label`/`to_label` live at the cable level here.
     *
     * @return list<array{cable_id: int, description: string, from_label: string, to_label: string, total_cores: int, mappable: bool, cores: list<array{core_id: int, tube_number: int, core_number_in_tube: int, tube_color: ?string, core_color: ?string}>}>
     */
    public function cableCoreConnections(FiberNode|Odp $node): array
    {
        $cables = $node->cablesAsFrom()->with('cores')->get()
            ->concat($node->cablesAsTo()->with('cores')->get());

        $groups = [];

        foreach ($cables as $cable) {
            $mappable = $this->morphCoords($cable->from_type, $cable->from_id) !== null
                && $this->morphCoords($cable->to_type, $cable->to_id) !== null;

            $groups[] = [
                'cable_id' => $cable->id,
                'description' => $this->describeCable($cable),
                'from_label' => $this->labelForMorph($cable->from_type, $cable->from_id),
                'to_label' => $this->labelForMorph($cable->to_type, $cable->to_id),
                'total_cores' => $cable->total_cores,
                'mappable' => $mappable,
                'cores' => $cable->cores->sortBy(['tube_number', 'core_number_in_tube'])
                    ->map(fn (FiberCore $core) => [
                        'core_id' => $core->id,
                        'tube_number' => $core->tube_number,
                        'core_number_in_tube' => $core->core_number_in_tube,
                        'tube_color' => $core->tube_color,
                        'core_color' => $core->core_color,
                    ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * v0.16.0 Langkah 9 — every cable with coordinates at both ends, as
     * {cable_id, label} options for the "Peta Topologi" page's own cable
     * picker. Unit of selection is ALWAYS the cable — never an individual
     * core — everywhere in this map feature.
     *
     * @return list<array{cable_id: int, label: string}>
     */
    public function mappableCableOptions(): array
    {
        $options = [];

        foreach (FiberCable::query()->orderBy('id')->get() as $cable) {
            if ($this->morphCoords($cable->from_type, $cable->from_id) === null
                || $this->morphCoords($cable->to_type, $cable->to_id) === null) {
                continue;
            }

            $options[] = [
                'cable_id' => $cable->id,
                'label' => $this->describeCable($cable),
            ];
        }

        return $options;
    }

    /**
     * v0.16.0 Langkah 8/9 — line descriptor for ONE selected CABLE on the
     * "Peta Topologi" map: the polyline runs source-endpoint → waypoints
     * (by sequence) → target-endpoint, in the neutral CABLE_LINE_COLOR
     * (never a per-core colour). Returns null when either endpoint has no
     * coordinates — nothing to draw.
     *
     * @return array{cable_id: int, label: string, color: string, endpoints: array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}, waypoints: list<array{0: float, 1: float}>}|null
     */
    public function cableLineData(FiberCable $cable): ?array
    {
        $from = $this->morphCoords($cable->from_type, $cable->from_id);
        $to = $this->morphCoords($cable->to_type, $cable->to_id);

        if ($from === null || $to === null) {
            return null;
        }

        $waypoints = $cable->waypoints()
            ->get(['latitude', 'longitude'])
            ->map(fn (FiberCableWaypoint $wp) => [(float) $wp->latitude, (float) $wp->longitude])
            ->all();

        return [
            'cable_id' => $cable->id,
            'label' => $this->describeCable($cable),
            'color' => self::CABLE_LINE_COLOR,
            'endpoints' => [$from, $to],
            'waypoints' => $waypoints,
        ];
    }

    /**
     * v0.16.0 Langkah 9/10 — a KML document for the selected categories:
     * one Placemark/Point per coordinate-bearing fiber_node / odp /
     * customer whose category is checked, one Placemark/LineString per
     * fiber_cable (both endpoints coordinated, routed through waypoints)
     * when 'cable' is checked. Built with DOMDocument (native) for correct
     * escaping. The caller zips this into a .kmz (ZipArchive, also native
     * — no new package).
     *
     * @param  list<string>|null  $categories  subset of MAP_CATEGORIES; null = all
     */
    public function buildTopologyKml(?array $categories = null): string
    {
        $categories = $categories === null
            ? self::MAP_CATEGORIES
            : $this->normaliseCategories($categories);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $kml = $dom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $dom->appendChild($kml);

        $doc = $dom->createElement('Document');
        $doc->appendChild($dom->createElement('name', 'Topologi Fiber BOSS App'));
        $kml->appendChild($doc);

        $lineStyle = $dom->createElement('Style');
        $lineStyle->setAttribute('id', 'cable');
        $ls = $dom->createElement('LineStyle');
        // KML colour is aabbggrr; CABLE_LINE_COLOR #1E3A8A -> ff8a3a1e
        $ls->appendChild($dom->createElement('color', 'ff8a3a1e'));
        $ls->appendChild($dom->createElement('width', '3'));
        $lineStyle->appendChild($ls);
        $doc->appendChild($lineStyle);

        $addPlacemark = function (string $name, string $description, \DOMElement $geometry) use ($dom, $doc) {
            $pm = $dom->createElement('Placemark');
            $pm->appendChild($dom->createElement('name', htmlspecialchars($name, ENT_XML1)));
            if ($description !== '') {
                $pm->appendChild($dom->createElement('description', htmlspecialchars($description, ENT_XML1)));
            }
            $pm->appendChild($geometry);
            $doc->appendChild($pm);
        };

        foreach ($this->topologyMapMarkers() as $marker) {
            if (! in_array($marker['node_type'], $categories, true)) {
                continue;
            }

            $point = $dom->createElement('Point');
            $point->appendChild($dom->createElement(
                'coordinates',
                $marker['longitude'].','.$marker['latitude'].',0'
            ));
            $addPlacemark($marker['label'], ucfirst((string) $marker['node_type']), $point);
        }

        if (in_array('customer', $categories, true)) {
            foreach ($this->topologyMapCustomers() as $customer) {
                $point = $dom->createElement('Point');
                $point->appendChild($dom->createElement(
                    'coordinates',
                    $customer['longitude'].','.$customer['latitude'].',0'
                ));
                $addPlacemark(
                    'Pelanggan: '.$customer['name'],
                    trim(($customer['address'] ?? '').' ['.$customer['status'].']'),
                    $point,
                );
            }
        }

        if (! in_array('cable', $categories, true)) {
            return $dom->saveXML() ?: '';
        }

        foreach (FiberCable::query()->orderBy('id')->get() as $cable) {
            $line = $this->cableLineData($cable);

            if ($line === null) {
                continue;
            }

            $coords = array_merge(
                [$line['endpoints'][0]],
                $line['waypoints'],
                [$line['endpoints'][1]],
            );

            $text = implode(' ', array_map(
                fn (array $p) => $p[1].','.$p[0].',0',
                $coords,
            ));

            $lineString = $dom->createElement('LineString');
            $lineString->appendChild($dom->createElement('tessellate', '1'));
            $lineString->appendChild($dom->createElement('coordinates', $text));

            $pm = $dom->createElement('Placemark');
            $pm->appendChild($dom->createElement('name', htmlspecialchars($line['label'], ENT_XML1)));
            $pm->appendChild($dom->createElement('styleUrl', '#cable'));
            $pm->appendChild($lineString);
            $doc->appendChild($pm);
        }

        return $dom->saveXML() ?: '';
    }

    /**
     * v0.16.0 Langkah 9/10 — zip buildTopologyKml()'s output into a .kmz
     * (a zip whose single entry is doc.kml). Returns the raw .kmz bytes.
     * ZipArchive is a native PHP extension — no package added.
     *
     * @param  list<string>|null  $categories  subset of MAP_CATEGORIES; null = all
     */
    public function buildTopologyKmz(?array $categories = null): string
    {
        $kml = $this->buildTopologyKml($categories);

        $path = tempnam(sys_get_temp_dir(), 'kmz');

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', $kml);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * v0.16.0 Langkah 8 — replace ALL waypoints of one cable with the
     * given ordered coordinate list (re-sequenced 1..n). An empty list
     * clears the route back to a straight line.
     *
     * @param  list<array{lat: float|string, lng: float|string}>  $points
     */
    public function replaceCableWaypoints(FiberCable $cable, array $points): void
    {
        DB::transaction(function () use ($cable, $points) {
            $cable->waypoints()->delete();

            $sequence = 1;
            foreach ($points as $point) {
                $cable->waypoints()->create([
                    'sequence' => $sequence++,
                    'latitude' => (float) $point['lat'],
                    'longitude' => (float) $point['lng'],
                ]);
            }
        });
    }

    /**
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private function morphCoords(string $type, int $id): ?array
    {
        $model = $type === FiberNode::class ? FiberNode::find($id) : Odp::find($id);

        if ($model === null || $model->latitude === null || $model->longitude === null) {
            return null;
        }

        return [(float) $model->latitude, (float) $model->longitude];
    }

    /**
     * v0.16.0 Langkah 5 — points selectable as the `to` endpoint of a new
     * outgoing cable FROM $source: every other topology point in the
     * tenant that isn't already a `to` endpoint of an existing cable from
     * this same source (nor $source itself).
     *
     * @return list<array{type: string, id: int, label: string}>
     */
    public function cableTargetCandidates(FiberNode|Odp $source): array
    {
        $alreadyChildren = $source->cablesAsFrom()
            ->get(['to_type', 'to_id'])
            ->map(fn (FiberCable $cable) => $cable->to_type.'#'.$cable->to_id)
            ->all();

        $isSelf = fn (string $type, int $id) => $type === $source::class && $id === $source->id;
        $taken = fn (string $type, int $id) => in_array($type.'#'.$id, $alreadyChildren, true);

        $candidates = [];

        foreach (FiberNode::query()->orderBy('local_label')->get(['id', 'local_label', 'node_type']) as $node) {
            if ($isSelf(FiberNode::class, $node->id) || $taken(FiberNode::class, $node->id)) {
                continue;
            }

            $candidates[] = [
                'type' => FiberNode::class,
                'id' => $node->id,
                'label' => ($node->local_label ?? $node->node_type->label()).' ('.$node->node_type->label().')',
            ];
        }

        foreach (Odp::query()->orderBy('code')->get(['id', 'code', 'name']) as $odp) {
            if ($isSelf(Odp::class, $odp->id) || $taken(Odp::class, $odp->id)) {
                continue;
            }

            $candidates[] = [
                'type' => Odp::class,
                'id' => $odp->id,
                'label' => "{$odp->code} - {$odp->name} (ODP)",
            ];
        }

        return $candidates;
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
     * v0.16.0 Langkah 7 — targets a new accessory can attach to from a
     * node's detail page: every cable touching the node (either endpoint)
     * plus every splitter mounted on it. Combined key "cable#{id}" /
     * "splitter#{id}" — same shape as FiberCableForm's toKey.
     *
     * @return list<array{key: string, label: string}>
     */
    public function accessoryTargetsForNode(FiberNode|Odp $node): array
    {
        $targets = [];

        $cables = $node->cablesAsFrom()->with(['from', 'to'])->get()
            ->merge($node->cablesAsTo()->with(['from', 'to'])->get())
            ->unique('id');

        foreach ($cables as $cable) {
            $targets[] = [
                'key' => "cable#{$cable->id}",
                'label' => $this->describeCable($cable),
            ];
        }

        foreach ($node->splitters()->get() as $splitter) {
            $targets[] = [
                'key' => "splitter#{$splitter->id}",
                'label' => "Splitter {$splitter->ratio}".($splitter->model !== null ? " ({$splitter->model})" : ''),
            ];
        }

        return $targets;
    }

    /**
     * v0.16.0 Langkah 7 — the suggested expected_loss_db to pre-fill when
     * a technician adds an accessory: a splitter's ratio-reference value
     * for a splitter target, or the accessory type's own default insertion
     * loss for a cable target. Never blocks — returns null if nothing
     * sensible is known.
     */
    public function suggestedAccessoryLoss(?string $targetKey, ?string $accessoryType, SplitterLossReferenceService $ref): ?float
    {
        if ($accessoryType !== null && str_starts_with((string) $targetKey, 'splitter#')) {
            $splitter = Splitter::query()->tenantScoped()->find((int) substr($targetKey, 9));

            if ($splitter !== null) {
                return $ref->expectedLossFor($splitter->ratio);
            }
        }

        $type = FiberAccessoryType::tryFrom((string) $accessoryType);

        return $type !== null ? $ref->defaultAccessoryLossFor($type) : null;
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

    /**
     * v0.16.0 Langkah 6/7 — port patch simulation for an OTB. One row per
     * physical port (1..port_count); each is either empty or occupied by a
     * FiberCore, with the core's colour and where the port connects to —
     * either a downstream node (the core's cable's far end) OR, when the
     * core carries an OLT link (Langkah 7), the OLT device + PON label.
     *
     * @return list<array{port: int, core: null|array<string, mixed>}>
     */
    public function otbPortSimulation(FiberNode $otb): array
    {
        $count = (int) ($otb->port_count ?? 0);
        $byPort = $this->coresFromNode($otb)
            ->filter(fn (FiberCore $core) => $core->port_number !== null)
            ->keyBy('port_number');

        $rows = [];
        for ($port = 1; $port <= $count; $port++) {
            $core = $byPort->get($port);
            $rows[] = [
                'port' => $port,
                'core' => $core === null ? null : $this->coreCardData($core),
            ];
        }

        return $rows;
    }

    /**
     * Every FiberCore belonging to a cable that ORIGINATES FROM $node —
     * i.e. the cores a technician can patch onto $node's own OTB ports.
     * Deliberately outgoing-only (unchanged from Langkah 6) — a Langkah 7
     * OLT link is additive metadata on one of these same cores, not a new
     * class of "portless uplink".
     *
     * @return Collection<int, FiberCore>
     */
    public function coresFromNode(FiberNode|Odp $node): Collection
    {
        $cableIds = $node->cablesAsFrom()->pluck('id');

        return FiberCore::query()
            ->whereIn('fiber_cable_id', $cableIds)
            ->with(['fiberCable', 'oltDevice.oltModel'])
            ->orderBy('fiber_cable_id')
            ->orderBy('tube_number')
            ->orderBy('core_number_in_tube')
            ->get();
    }

    /**
     * v0.16.0 Langkah 6 — every outgoing core of an OTB, shaped for the
     * port-assignment table (colours + destination + current port + OLT).
     *
     * @return list<array<string, mixed>>
     */
    public function assignableOtbCores(FiberNode $otb): array
    {
        return $this->coresFromNode($otb)
            ->map(fn (FiberCore $core) => $this->coreCardData($core))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function coreCardData(FiberCore $core): array
    {
        $cable = $core->fiberCable;
        $oltLabel = $this->oltLabelFor($core);

        return [
            'core_id' => $core->id,
            'cable_id' => $core->fiber_cable_id,
            'cable_description' => $cable !== null ? $this->describeCable($cable) : 'Kabel dihapus',
            'tube_number' => $core->tube_number,
            'core_number_in_tube' => $core->core_number_in_tube,
            'tube_color' => $core->tube_color,
            'core_color' => $core->core_color,
            'port_number' => $core->port_number,
            'olt_device_id' => $core->olt_device_id,
            'olt_pon_port_label' => $core->olt_pon_port_label,
            'connects_to_olt' => $oltLabel !== null,
            'destination' => $oltLabel ?? ($cable !== null
                ? $this->labelForMorph($cable->to_type, $cable->to_id)
                : 'Tujuan tidak diketahui'),
        ];
    }

    private function oltLabelFor(FiberCore $core): ?string
    {
        if ($core->olt_device_id === null) {
            return null;
        }

        $name = $core->oltDevice?->name ?? "OLT #{$core->olt_device_id}";
        $pon = trim((string) $core->olt_pon_port_label);

        return 'OLT: '.$name.($pon !== '' ? ' - '.$pon : '');
    }

    /**
     * v0.16.0 Langkah 7 — OLT devices selectable as a core's direct-patch
     * target (tenant/reseller scoped via OltDevice's own global scopes).
     *
     * @return list<array{id: int, label: string}>
     */
    public function oltDeviceOptions(): array
    {
        return OltDevice::query()
            ->with('oltModel')
            ->orderBy('name')
            ->get()
            ->map(fn (OltDevice $olt) => [
                'id' => $olt->id,
                'label' => $olt->name.($olt->oltModel !== null ? " ({$olt->oltModel->name})" : ''),
            ])
            ->all();
    }

    /**
     * v0.16.0 Langkah 7 — the last few port-assignment changes on an OTB,
     * for the "riwayat singkat" under the Simulasi Port table.
     *
     * @return Collection<int, FiberCorePortLog>
     */
    public function otbPortLogs(FiberNode $otb, int $limit = 3): Collection
    {
        return FiberCorePortLog::query()
            ->where('fiber_node_id', $otb->id)
            ->with(['performedBy', 'fiberCore'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * v0.16.0 Langkah 6/7 — assign (or clear, when $portNumber is null)
     * the OTB port a single core is patched onto, plus an optional OLT
     * direct-patch link. If the target port is currently held by ANOTHER
     * core on this OTB, that core is auto-released (not a conflict error) —
     * a per-row quick edit, unlike the bulk save's stricter "two rows can't
     * both claim one port in the same submit". Audit-logged for every core
     * whose assignment actually changes.
     */
    public function assignCorePort(
        FiberCore $core,
        FiberNode $otb,
        ?int $portNumber,
        ?int $oltDeviceId = null,
        ?string $oltPonPortLabel = null,
    ): FiberCore {
        $this->assertCoreBelongsToOtb($core, $otb);
        $this->assertPortInRange($portNumber, $otb);
        $this->assertOltDevice($oltDeviceId);

        DB::transaction(function () use ($core, $otb, $portNumber, $oltDeviceId, $oltPonPortLabel) {
            if ($portNumber !== null) {
                $holder = $this->coresFromNode($otb)
                    ->first(fn (FiberCore $other) => $other->id !== $core->id && $other->port_number === $portNumber);

                if ($holder !== null) {
                    $this->applyCoreAssignment($holder, $otb, null, null, null);
                }
            }

            $this->applyCoreAssignment($core, $otb, $portNumber, $oltDeviceId, $oltPonPortLabel);
        });

        return $core->refresh();
    }

    /**
     * v0.16.0 Langkah 7 — bulk save every port input on the OTB detail
     * page at once, ALL-OR-NOTHING. The whole submitted set is validated
     * before any write; if a row is invalid nothing is saved. Uniqueness
     * here means "no two DIFFERENT cores claim the same non-null port IN
     * THIS SUBMIT" — a core moving to a port another core is vacating in
     * the same submit is fine.
     *
     * @param  array<int, array{port?: mixed, olt_device_id?: mixed, olt_pon_port_label?: mixed}>  $rows  keyed by core id
     * @return array<int, string> per-core error messages; empty on success (and then everything is saved)
     */
    public function assignCorePorts(FiberNode $otb, array $rows): array
    {
        $max = (int) ($otb->port_count ?? 0);
        $cores = $this->coresFromNode($otb)->keyBy('id');

        $errors = [];
        $parsed = [];
        $portToCores = [];

        foreach ($rows as $coreId => $row) {
            $coreId = (int) $coreId;

            if (! $cores->has($coreId)) {
                continue;
            }

            $rawPort = trim((string) ($row['port'] ?? ''));
            $port = $rawPort === '' ? null : (int) $rawPort;
            $oltId = ($row['olt_device_id'] ?? '') === '' ? null : (int) $row['olt_device_id'];
            $ponLabel = trim((string) ($row['olt_pon_port_label'] ?? '')) ?: null;

            if ($port !== null && ($port < 1 || $port > $max)) {
                $errors[$coreId] = "Nomor port harus antara 1 dan {$max}.";

                continue;
            }

            if ($oltId !== null && OltDevice::query()->whereKey($oltId)->doesntExist()) {
                $errors[$coreId] = 'Perangkat OLT tidak ditemukan.';

                continue;
            }

            if ($oltId !== null && $port === null) {
                $errors[$coreId] = 'Tautan OLT hanya untuk core yang punya nomor port.';

                continue;
            }

            $parsed[$coreId] = ['port' => $port, 'olt_device_id' => $oltId, 'olt_pon_port_label' => $port === null ? null : $ponLabel, 'reset_olt' => $oltId === null];

            if ($port !== null) {
                $portToCores[$port][] = $coreId;
            }
        }

        foreach ($portToCores as $port => $coreIds) {
            if (count($coreIds) > 1) {
                foreach ($coreIds as $coreId) {
                    $errors[$coreId] = "Port {$port} diklaim lebih dari satu core dalam simpan ini.";
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($parsed, $cores, $otb) {
            foreach ($parsed as $coreId => $data) {
                $this->applyCoreAssignment(
                    $cores->get($coreId),
                    $otb,
                    $data['port'],
                    $data['reset_olt'] ? null : $data['olt_device_id'],
                    $data['reset_olt'] ? null : $data['olt_pon_port_label'],
                );
            }
        });

        return [];
    }

    private function assertCoreBelongsToOtb(FiberCore $core, FiberNode $otb): void
    {
        $cable = $core->fiberCable;

        if ($cable === null || $cable->from_type !== FiberNode::class || (int) $cable->from_id !== $otb->id) {
            throw new InvalidArgumentException('Core ini bukan berasal dari OTB tersebut.');
        }
    }

    private function assertPortInRange(?int $portNumber, FiberNode $otb): void
    {
        if ($portNumber === null) {
            return;
        }

        $max = (int) ($otb->port_count ?? 0);

        if ($portNumber < 1 || $portNumber > $max) {
            throw new InvalidArgumentException("Nomor port harus antara 1 dan {$max}.");
        }
    }

    private function assertOltDevice(?int $oltDeviceId): void
    {
        if ($oltDeviceId !== null && OltDevice::query()->whereKey($oltDeviceId)->doesntExist()) {
            throw new InvalidArgumentException('Perangkat OLT tidak ditemukan.');
        }
    }

    /**
     * Applies + audit-logs one core's assignment, only writing a log row
     * when something actually changed.
     */
    private function applyCoreAssignment(
        FiberCore $core,
        FiberNode $otb,
        ?int $portNumber,
        ?int $oltDeviceId,
        ?string $oltPonPortLabel,
    ): void {
        $oldPort = $core->port_number;
        $oldOltLabel = $this->oltLabelFor($core);

        $core->update([
            'port_number' => $portNumber,
            'olt_device_id' => $portNumber === null ? null : $oltDeviceId,
            'olt_pon_port_label' => ($portNumber === null || $oltDeviceId === null) ? null : $oltPonPortLabel,
        ]);
        $core->refresh();

        $newOltLabel = $this->oltLabelFor($core);

        if ($oldPort === $core->port_number && $oldOltLabel === $newOltLabel) {
            return;
        }

        FiberCorePortLog::create([
            'fiber_core_id' => $core->id,
            'fiber_node_id' => $otb->id,
            'performed_by' => Auth::id(),
            'old_port_number' => $oldPort,
            'new_port_number' => $core->port_number,
            'old_olt_label' => $oldOltLabel,
            'new_olt_label' => $newOltLabel,
        ]);
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
     * v0.16.0 Langkah 13 — short endpoint label for describeCable():
     * fiber_node.local_label (or its type label), or odp.code — NOT the
     * "code - name" form labelForMorph() uses. A soft-deleted FiberNode
     * (the only soft-deletable endpoint type) or a missing row falls back
     * to "Titik dihapus" so the cable label never crashes or shows null.
     */
    private function morphShortLabel(string $type, int $id): string
    {
        if ($type === FiberNode::class) {
            $node = FiberNode::find($id);

            return $node?->local_label ?? $node?->node_type?->label() ?? 'Titik dihapus';
        }

        return Odp::find($id)?->code ?? 'Titik dihapus';
    }

    /**
     * v0.16.0 Langkah 13 — descriptive, ID-free cable name used
     * EVERYWHERE a cable is shown to a user (CapacityReport, the Peta
     * Topologi checklist/chips, FiberNodeDetail's "Koneksi Core" table,
     * the KMZ LineString name, the accessory "Terpasang di" dropdown).
     * Format: "Kabel {N} Core {asal} ↔ {tujuan}". The numeric id stays
     * only in URLs/plumbing (?cable=5), never in reading text.
     */
    public function describeCable(FiberCable $cable): string
    {
        return 'Kabel '.$cable->total_cores.' Core '
            .$this->morphShortLabel($cable->from_type, $cable->from_id)
            .' ↔ '.$this->morphShortLabel($cable->to_type, $cable->to_id);
    }

    /**
     * v0.16.0 Langkah 13 — true when a morph pair still resolves to a
     * live (non-soft-deleted) row. FiberNode is the only soft-deletable
     * endpoint/owner type, so FiberNode::whereKey()->exists() (which
     * honours SoftDeletingScope) is the whole check; Odp always exists if
     * its id is valid. Mirrors what morphCoords() already does for the
     * map — used by capacityReport() to keep the two consistent.
     */
    private function morphExists(string $type, int $id): bool
    {
        return $type === FiberNode::class
            ? FiberNode::whereKey($id)->exists()
            : Odp::whereKey($id)->exists();
    }

    private function cableEndpointsIntact(FiberCable $cable): bool
    {
        return $this->morphExists($cable->from_type, $cable->from_id)
            && $this->morphExists($cable->to_type, $cable->to_id);
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
    /**
     * v0.16.0 Langkah 11 — port capacity per ODP (used = status 'used',
     * same count capacityReport() has always used). The single source of
     * this figure — capacityReport() AND topologyMapMarkers()'s ODP popup
     * (Langkah 11 Bagian E) both call this, no duplicated query.
     *
     * @return Collection<int, array{odp_id: int, code: string, name: string, used: int, total: int, percent: ?int, zone: string, zone_label: string, zone_color: string}>
     */
    public function odpCapacities(): Collection
    {
        return Odp::query()
            ->withCount(['ports as used_ports_count' => fn ($query) => $query->where('status', OdpPortStatus::Used->value)])
            ->get()
            ->map(function (Odp $odp) {
                $percent = $odp->total_ports > 0
                    ? (int) round($odp->used_ports_count / $odp->total_ports * 100)
                    : null;
                $zone = $this->capacityZone($percent);

                return [
                    'odp_id' => $odp->id,
                    'code' => $odp->code,
                    'name' => $odp->name,
                    'used' => (int) $odp->used_ports_count,
                    'total' => (int) $odp->total_ports,
                    'percent' => $percent,
                    'zone' => $zone['key'],
                    'zone_label' => $zone['label'],
                    'zone_color' => $zone['color'],
                ];
            })
            ->keyBy('odp_id');
    }

    /**
     * v0.16.0 Langkah 11 — the traffic-light zone for a capacity percent,
     * matching partials/capacity-progress-bar.blade.php EXACTLY (>80 red /
     * ≥60 amber / else green; null = unknown). Returned as data so the
     * Leaflet popup renders the same words/colours the Capacity Report
     * bar does, without re-deriving thresholds in JS.
     *
     * @return array{key: string, label: string, color: string}
     */
    public function capacityZone(?int $percent): array
    {
        return match (true) {
            $percent === null => ['key' => 'unknown', 'label' => 'kapasitas tidak diketahui', 'color' => '#9CA3AF'],
            $percent > 80 => ['key' => 'penuh', 'label' => 'penuh', 'color' => '#EF4444'],
            $percent >= 60 => ['key' => 'hampir-penuh', 'label' => 'hampir penuh', 'color' => '#F59E0B'],
            default => ['key' => 'longgar', 'label' => 'longgar', 'color' => '#22C55E'],
        };
    }

    public function capacityReport(?string $search = null): array
    {
        // ODP has no soft-delete (only FiberNode does), and the map shows
        // every ODP regardless of whether its parent node was deleted —
        // so, unlike cables/splitters below, the ODP list needs no
        // endpoint-liveness filter to stay consistent with the map.
        $odps = $this->odpCapacities()
            ->map(fn (array $cap) => (object) [
                'id' => $cap['odp_id'],
                'category' => 'odp',
                'label' => "{$cap['code']} - {$cap['name']}",
                'used' => $cap['used'],
                'total' => $cap['total'],
                'percent' => $cap['percent'] ?? 0,
            ])
            ->values();

        // v0.16.0 Langkah 13 — a Splitter whose owner FiberNode was
        // soft-deleted is orphaned; drop it, same reasoning as the cable
        // filter below. (Odp — the other owner type — can't be
        // soft-deleted, so this only ever filters FiberNode-owned rows.)
        $splitters = Splitter::query()
            ->tenantScoped()
            ->withCount('accessories')
            ->get()
            ->filter(fn (Splitter $splitter) => $this->morphExists($splitter->owner_type, $splitter->owner_id))
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
            })
            ->values();

        // v0.16.0 Langkah 13 — exclude cables whose from/to endpoint is a
        // soft-deleted FiberNode, so this report matches the Peta Topologi
        // / KMZ (both already drop such cables via morphCoords()).
        $cables = FiberCable::query()
            ->withCount(['cores as used_cores_count' => fn ($query) => $query->where('status', FiberCoreStatus::Used->value)])
            ->get()
            ->filter(fn (FiberCable $cable) => $this->cableEndpointsIntact($cable))
            ->map(fn (FiberCable $cable) => (object) [
                'id' => $cable->id,
                'category' => 'cable',
                'label' => $this->describeCable($cable),
                'used' => $cable->used_cores_count,
                'total' => $cable->total_cores,
                'percent' => $cable->total_cores > 0 ? (int) round($cable->used_cores_count / $cable->total_cores * 100) : 0,
            ])
            ->values();

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
