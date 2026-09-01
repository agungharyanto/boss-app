{{--
    v0.16.0 Langkah 4 — traffic-light thresholds (green <60%, amber
    60-80%, red >80%, matching the CapacityReport's own ">80% penuh"
    early-warning filter) via ui-ux-pro-max's bullet-chart color guidance,
    adapted to a plain Tailwind bar. Percent text is always shown next to
    the bar — color is never the only signal, same accessibility
    reasoning as core-map.blade.php's swatches.

    Re-polish pass (ui-ux-pro-max Skill, same v2.13.0 ruleset): the
    bullet-chart A11y note also asks for the *threshold zone* to be
    labelled in words ("color is supplementary", "focus reveals the same
    detail as hover"). The visible number stays as-is; the zone label
    (longgar/hampir penuh/penuh) is exposed to screen readers via
    role="img" + aria-label on the wrapper — pure presentation text
    derived from the already-computed $percent, no logic/data change.
--}}
@php
    $barColor = match (true) {
        $percent === null => 'bg-gray-300',
        $percent > 80 => 'bg-red-500',
        $percent >= 60 => 'bg-amber-500',
        default => 'bg-green-500',
    };
    $textColor = match (true) {
        $percent === null => 'text-gray-400',
        $percent > 80 => 'text-red-700',
        $percent >= 60 => 'text-amber-700',
        default => 'text-green-700',
    };
    $zoneLabel = match (true) {
        $percent === null => __('kapasitas tidak diketahui'),
        $percent > 80 => __('penuh'),
        $percent >= 60 => __('hampir penuh'),
        default => __('longgar'),
    };
    $ariaLabel = $percent !== null
        ? __('Kapasitas').' '.$percent.'% — '.$zoneLabel
        : $zoneLabel;
@endphp
<div class="flex items-center gap-2" role="img" aria-label="{{ $ariaLabel }}">
    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden min-w-[80px]">
        <div class="h-full {{ $barColor }} rounded-full" style="width: {{ $percent !== null ? min($percent, 100) : 0 }}%"></div>
    </div>
    <span class="text-xs font-medium {{ $textColor }} w-10 text-right shrink-0" aria-hidden="true">{{ $percent !== null ? $percent.'%' : '-' }}</span>
</div>
