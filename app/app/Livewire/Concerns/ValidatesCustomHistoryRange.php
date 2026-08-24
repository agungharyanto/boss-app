<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * v0.8.3 — "Custom Range" 6th tab, shared by every history modal that
 * already reuses CpeSignalHistoryRange's 5-tab vocabulary
 * (App\Livewire\Network\CpeSignalHistoryGraph, DeviceHistoryModal,
 * DeviceTrafficGraph — see CLAUDE.md's own section for the full list of
 * places this enum gets reused). Only the VALIDATION + shared state
 * lives here — each host component still calls its own specific
 * service/method afterward (CpeSignalHistoryQueryService::
 * customSeriesFor(), or LibreNmsService's `?Carbon $endAt` parameter),
 * since those genuinely differ per component. Forcing that part into a
 * generic trait method would need an abstract-method contract PHP traits
 * can't cleanly enforce across unrelated host classes — not worth the
 * indirection for 3 call sites.
 */
trait ValidatesCustomHistoryRange
{
    public bool $customRangeMode = false;

    public string $customFrom = '';

    public string $customTo = '';

    public ?string $customRangeError = null;

    /**
     * Switches the tab UI into "Custom" — does NOT load anything yet
     * (the sprint brief's own explicit "Tombol Terapkan untuk submit"
     * requirement: date inputs appear, but nothing is queried until the
     * admin explicitly applies the range).
     */
    public function selectCustomRangeTab(): void
    {
        $this->customRangeMode = true;
        $this->customRangeError = null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null null on validation failure — $this->customRangeError is set for the view to render
     */
    private function validateCustomRange(): ?array
    {
        $this->customRangeError = null;

        if ($this->customFrom === '' || $this->customTo === '') {
            $this->customRangeError = 'Tanggal "Dari" dan "Sampai" wajib diisi.';

            return null;
        }

        try {
            $from = Carbon::parse($this->customFrom)->startOfDay();
            $to = Carbon::parse($this->customTo)->endOfDay();
        } catch (Throwable $e) {
            $this->customRangeError = 'Format tanggal tidak valid.';

            return null;
        }

        if ($to->lt($from)) {
            $this->customRangeError = '"Sampai" tidak boleh sebelum "Dari".';

            return null;
        }

        // 730 days (~2 years) — the sprint brief's own suggested cap
        // against an unbounded query ("supaya tidak query berlebihan").
        if ($from->diffInDays($to) > 730) {
            $this->customRangeError = 'Rentang maksimum adalah 2 tahun.';

            return null;
        }

        return [$from, $to];
    }
}
