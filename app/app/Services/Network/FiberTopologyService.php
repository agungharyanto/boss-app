<?php

namespace App\Services\Network;

use App\Enums\FiberNodeType;
use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberNode;
use App\Models\Odp;
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
}
