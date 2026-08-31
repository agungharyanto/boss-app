{{--
    v0.16.0 Langkah 4 — traffic-light thresholds (green <60%, amber
    60-80%, red >80%, matching the CapacityReport's own ">80% penuh"
    early-warning filter) via ui-ux-pro-max's bullet-chart color guidance,
    adapted to a plain Tailwind bar. Percent text is always shown next to
    the bar — color is never the only signal, same accessibility
    reasoning as core-map.blade.php's swatches.
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
@endphp
<div class="flex items-center gap-2">
    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden min-w-[80px]">
        <div class="h-full {{ $barColor }} rounded-full" style="width: {{ $percent !== null ? min($percent, 100) : 0 }}%"></div>
    </div>
    <span class="text-xs font-medium {{ $textColor }} w-10 text-right shrink-0">{{ $percent !== null ? $percent.'%' : '-' }}</span>
</div>
