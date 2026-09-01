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
     * PON tree splitters (1:N) — theoretical = 10*log10(N), typical adds
     * real-world excess/uniformity loss. Asymmetric FBT tap couplers
     * (X:Y, physically 1x2) — 'theoretical' is the ideal split loss of the
     * LOWER-percentage (tap) port, 'typical' includes excess loss; the
     * higher-percentage pass port always sees far less and isn't the
     * number a technician pre-fills a worst-case redaman suggestion from.
     *
     * v0.16.0 Langkah 5 — the odd 1:N ratios (1:3/1:5/1:6/1:7) and the
     * asymmetric set were added here; the form's ratio field stays FREE
     * TEXT (an HTML datalist only suggests these), and expectedLossFor()
     * still returns null safely for anything not listed.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const RATIO_REFERENCE = [
        '1:2' => [3.01, 3.5],
        '1:3' => [4.77, 5.8],
        '1:4' => [6.02, 7.2],
        '1:5' => [6.99, 8.3],
        '1:6' => [7.78, 9.2],
        '1:7' => [8.45, 9.9],
        '1:8' => [9.03, 10.5],
        '1:16' => [12.04, 13.5],
        '1:32' => [15.05, 17.1],
        '1:64' => [18.06, 20.5],
        '50:50' => [3.01, 3.6],
        '40:60' => [3.98, 4.7],
        '30:70' => [5.23, 6.0],
        '20:80' => [6.99, 7.8],
        '10:90' => [10.0, 11.0],
    ];

    /**
     * Suggestions for the form's free-text ratio field's HTML datalist —
     * ordered PON tree ratios first, then asymmetric taps. Purely a UX
     * convenience; a technician can still type any ratio not on this list.
     *
     * @return list<string>
     */
    public function suggestedRatios(): array
    {
        return array_keys(self::RATIO_REFERENCE);
    }

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
