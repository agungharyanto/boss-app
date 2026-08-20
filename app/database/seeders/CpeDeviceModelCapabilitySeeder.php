<?php

namespace Database\Seeders;

use App\Models\CpeDeviceModelCapability;
use Illuminate\Database\Seeder;

/**
 * Seeded 2026-08-19 from a real, live query against the whole GenieACS
 * `devices` collection — for every OUI+ProductClass combo in this fleet,
 * which WLANConfiguration indices actually exist (not just which ones
 * have a non-empty SSID; a discovered-but-blank slot still proves the
 * hardware has it) across EVERY device of that combo, not just one sample.
 *
 * Deliberately only seeds combos where 2+ distinct indices were actually
 * observed — a combo that has only ever shown index 1 is NOT given a row
 * here (max_ssid_slots=1 would risk permanently hiding slots 2-4 that just
 * haven't synced yet, the same "path/value convergence lag" already
 * documented for MAC/PPPoE/AssociatedDevice elsewhere in this codebase —
 * whereas the conservative default of 4 keeps those slots visible-but-empty
 * and ready to populate once real data lands). Only combos with confirmed
 * real evidence of going beyond the default get an explicit row.
 *
 * `supports_5g` = true wherever index 5 was observed — this fleet has
 * NEVER shown index 6/7/8 on any device of any combo (checked directly,
 * not assumed), so this catalog does not claim the theoretical 8-slot
 * (1&4 PPPoE, 5&8 hotspot) shape Agung described for 5G-capable
 * hardware — only 1-5 has ever actually been confirmed. Flip
 * max_ssid_slots to 8 for any combo where a real device is later found
 * with index 6/7/8 populated, not before.
 *
 * A real, cross-vendor structural finding from this same query: the
 * H3-2s/H3-2S XPON family (and 702E22/M32X-5G, B4E46B/M12X5G XPON) show
 * indices [1, 5] — index 1 (PPPoE main) and 5 (TOKEN WIFI/hotspot,
 * confirmed by Agung's own terminology) with a genuine GAP at 2/3/4,
 * consistently across every device of these OUIs, not a one-off. This
 * seeder still sets max_ssid_slots=5 for them per the requested "render
 * 1..N, empty rows for gaps" design — rows 2-4 will show as empty/
 * "Nonaktif" placeholders on the CPE detail page, not because we believe
 * those slots are configurable-but-off, but because that's what the task
 * explicitly asked for; if a real device of this family is EVER found
 * with genuine data at index 2/3/4, that would contradict this pattern
 * and should be investigated as a new finding, not silently overwritten.
 */
class CpeDeviceModelCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        // oui => [product_class => [max_ssid_slots, supports_5g, sample_device_id]]
        $observed = [
            ['00259E', 'EG8141A5', 2, false, '00259E-EG8141A5-48575443796B91A7'],
            ['044F7A', 'H3-2s', 5, true, '044F7A-H3%2D2s-CMDC14406044'],
            ['0815AE', 'H3-2s', 5, true, '0815AE-H3%2D2s-CMDCA474672A'],
            ['1C25E1', 'GM220-S', 4, false, '1C25E1-GM220%2DS-CIOT1068C4F0'],
            ['241281', 'H3-2s', 5, true, '241281-H3%2D2s-CMDCA213E51A'],
            ['347839', 'F663NV9', 4, false, '347839-F663NV9-ZTEGCB399CEB'],
            ['448EEC', 'H3-2S XPON', 5, true, '448EEC-H3%2D2S%20XPON-CMDCA11244B4'],
            ['4C46D1', 'GL-01', 4, false, '4C46D1-GL%2D01-123454C46D14442DD'],
            ['507097', 'H3-2S XPON', 5, true, '507097-H3%2D2S%20XPON-CMDCA449F05E'],
            ['6C0F0B', 'H3-2S XPON', 5, true, '6C0F0B-H3%2D2S%20XPON-CMDCA44CC879'],
            ['702E22', 'M32X-5G', 5, true, '702E22-M32X%2D5G-RTEGC62B2147'],
            ['7C6A60', 'H3-2S XPON', 5, true, '7C6A60-H3%2D2S%20XPON-CMDCA4576F3D'],
            ['90F3B8', 'H3-2s', 5, true, '90F3B8-H3%2D2s-CMDCA10274AB'],
            ['A4F33B', 'GM219', 4, false, 'A4F33B-GM219-ZICG298BF2F9'],
            ['A4F33B', 'GM220-S', 4, false, 'A4F33B-GM220%2DS-ZICG296BEA12'],
            ['A861DF', 'H3-2S XPON', 5, true, 'A861DF-H3%2D2S%20XPON-CMDCA44F9991'],
            ['A861DF', 'H3-2s', 5, true, 'A861DF-H3%2D2s-CMDCA108CE3B'],
            ['B4E46B', 'M12X5G XPON', 5, true, 'B4E46B-M12X5G%20XPON-ZICG278AB602'],
            ['BC9E2C', 'H3-2S XPON', 5, true, 'BC9E2C-H3%2D2S%20XPON-CMDCA46FFE00'],
            ['C80C53', 'H3-2s', 5, true, 'C80C53-H3%2D2s-CMDC11F3363F'],
            ['DC7CF7', 'H3-2s', 5, true, 'DC7CF7-H3%2D2s-CMDC146A2EB0'],
            ['EC9B2D', 'H3-2S XPON', 5, true, 'EC9B2D-H3%2D2S%20XPON-CMDCA453D0FE'],
            ['F86CE1', 'GM219', 4, false, 'F86CE1-GM219-ZICG299033AD'],
            ['FC2E19', 'H3-2S XPON', 5, true, 'FC2E19-H3%2D2S%20XPON-CMDCA449AB05'],
        ];

        foreach ($observed as [$oui, $productClass, $maxSlots, $supports5g, $sampleDeviceId]) {
            CpeDeviceModelCapability::query()->updateOrCreate(
                ['oui' => $oui, 'product_class' => $productClass],
                [
                    'max_ssid_slots' => $maxSlots,
                    'supports_5g' => $supports5g,
                    'verified_at' => now(),
                    'verified_against_device_id' => $sampleDeviceId,
                    'notes' => "Discovered via live query against every device of this OUI+ProductClass in GenieACS's own devices collection (2026-08-19), not a single-sample guess — {$maxSlots} is the highest WLANConfiguration index found across ALL of them.",
                ]
            );
        }
    }
}
