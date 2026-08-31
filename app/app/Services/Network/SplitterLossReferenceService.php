<?php

namespace App\Services\Network;

use App\Enums\FiberAccessoryType;

/**
 * v0.16.0 — Core Network Infrastructure Management. Non-blocking reference
 * values only — a splitter's real, measured redaman is what actually gets
 * stored (Splitter itself has no loss column of its own; real
 * loss/redaman lives on FiberAccessory rows attached to it, per the
 * sprint's own schema). expectedLossFor() exists purely to pre-fill a
 * sensible suggestion in a future form (Langkah 3) — a custom ratio
 * outside the known table returns null rather than erroring, since an
 * unknown ratio is a completely normal, real case (splitter hardware
 * comes in many ratios not in any fixed reference table).
 */
class SplitterLossReferenceService
{
    /**
     * ratio => [theoretical_db, typical_db].
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const RATIO_REFERENCE = [
        '1:2' => [3.01, 3.5],
        '1:4' => [6.02, 7.2],
        '1:8' => [9.03, 10.5],
        '1:16' => [12.04, 13.5],
        '1:32' => [15.05, 17.1],
        '1:64' => [18.06, 20.5],
    ];

    /**
     * Default expected_loss_db per accessory type — a splice/connector's
     * own typical insertion loss, independent of any splitter ratio.
     */
    private const ACCESSORY_DEFAULT_LOSS_DB = [
        'splice_fusion' => 0.15,
        'splice_mechanical' => 0.2,
        'connector' => 0.25,
        // A pin adaptor is treated as an individual connector for
        // reference-loss purposes.
        'pin_adaptor' => 0.25,
    ];

    /**
     * Returns the typical_db reference value for a known ratio, or null
     * for a custom/unrecognized ratio string — never throws, this is a
     * suggestion, not a validation rule.
     */
    public function expectedLossFor(string $ratio): ?float
    {
        return self::RATIO_REFERENCE[$ratio][1] ?? null;
    }

    public function defaultAccessoryLossFor(FiberAccessoryType $type): float
    {
        return self::ACCESSORY_DEFAULT_LOSS_DB[$type->value];
    }
}
