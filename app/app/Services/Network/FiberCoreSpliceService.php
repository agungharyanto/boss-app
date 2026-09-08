<?php

namespace App\Services\Network;

use App\Models\FiberCable;
use App\Models\FiberCore;
use App\Models\FiberCoreSplice;
use App\Models\FiberNode;
use App\Models\Odp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * v0.16.1 Bagian B — the ONLY place a fiber_core_splices row is written
 * or removed. Guards (all as InvalidArgumentException, caught + surfaced
 * by the Livewire component):
 *   - not a core to itself
 *   - the two cores must be on DIFFERENT cables
 *   - each core's cable must actually touch $node (either end) — a splice
 *     only makes physical sense where both cables terminate
 *   - neither core may already be in a splice (on either side) — the
 *     per-column DB UNIQUE is only a partial backstop for this
 *   - loss_db, if given, must be >= 0
 */
class FiberCoreSpliceService
{
    public function createSplice(
        FiberNode|Odp $node,
        FiberCore $from,
        FiberCore $to,
        ?float $lossDb = null,
        ?string $note = null,
    ): FiberCoreSplice {
        $this->assertValid($node, $from, $to, $lossDb);

        return FiberCoreSplice::create([
            'splice_node_type' => $node::class,
            'splice_node_id' => $node->id,
            'from_fiber_core_id' => $from->id,
            'to_fiber_core_id' => $to->id,
            'loss_db' => $lossDb,
            'note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
            'performed_by' => Auth::id(),
        ]);
    }

    public function deleteSplice(FiberCoreSplice $splice): void
    {
        $splice->delete();
    }

    /**
     * @return Collection<int, FiberCoreSplice>
     */
    public function splicesForNode(FiberNode|Odp $node): Collection
    {
        return FiberCoreSplice::query()
            ->where('splice_node_type', $node::class)
            ->where('splice_node_id', $node->id)
            ->with(['fromCore.fiberCable', 'toCore.fiberCable', 'performedBy'])
            ->orderBy('id')
            ->get();
    }

    private function assertValid(FiberNode|Odp $node, FiberCore $from, FiberCore $to, ?float $lossDb): void
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Tidak bisa menyambung sebuah core ke dirinya sendiri.');
        }

        if ((int) $from->fiber_cable_id === (int) $to->fiber_cable_id) {
            throw new InvalidArgumentException('Kedua core harus berasal dari kabel yang berbeda.');
        }

        if ($lossDb !== null && $lossDb < 0) {
            throw new InvalidArgumentException('Redaman splice tidak boleh negatif.');
        }

        foreach ([['awal', $from], ['akhir', $to]] as [$side, $core]) {
            $cable = $core->fiberCable;

            if ($cable === null || ! $this->cableTouchesNode($cable, $node)) {
                throw new InvalidArgumentException("Core sisi \"{$side}\" bukan dari kabel yang terhubung ke titik ini.");
            }

            if ($this->coreAlreadySpliced($core->id)) {
                throw new InvalidArgumentException("Core sisi \"{$side}\" sudah tersambung ke core lain — lepas dulu splice yang lama.");
            }
        }

        // v0.16.1 Revisi C — a through-splice must connect an INCOMING
        // cable (its `to` end is this node) with an OUTGOING one (its
        // `from` end is this node). Reject two incoming or two outgoing.
        // No limit on how many cables of each direction touch the node —
        // this is purely about the orientation of THIS pair. A self-loop
        // cable (both ends this node) satisfies either side.
        $fromCable = $from->fiberCable;
        $toCable = $to->fiberCable;

        $fromIn = $this->cableEndsAt($fromCable, $node, 'to');
        $fromOut = $this->cableEndsAt($fromCable, $node, 'from');
        $toIn = $this->cableEndsAt($toCable, $node, 'to');
        $toOut = $this->cableEndsAt($toCable, $node, 'from');

        if (! (($fromIn && $toOut) || ($fromOut && $toIn))) {
            throw new InvalidArgumentException('Splice harus menghubungkan kabel masuk dengan kabel keluar (bukan dua-duanya masuk atau dua-duanya keluar).');
        }
    }

    private function cableEndsAt(FiberCable $cable, FiberNode|Odp $node, string $end): bool
    {
        return $cable->{"{$end}_type"} === $node::class
            && (int) $cable->{"{$end}_id"} === $node->id;
    }

    private function cableTouchesNode(FiberCable $cable, FiberNode|Odp $node): bool
    {
        return ($cable->from_type === $node::class && (int) $cable->from_id === $node->id)
            || ($cable->to_type === $node::class && (int) $cable->to_id === $node->id);
    }

    private function coreAlreadySpliced(int $coreId): bool
    {
        return FiberCoreSplice::query()
            ->where('from_fiber_core_id', $coreId)
            ->orWhere('to_fiber_core_id', $coreId)
            ->exists();
    }
}
