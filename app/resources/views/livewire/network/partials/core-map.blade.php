{{--
    v0.16.0 Langkah 4 — small colored-dot core map, grouped by tube, one
    row per tube. Text-labeled (tube/core number + color name) on every
    dot via `title`, not color alone — colored swatches never rely on
    color to convey meaning, per accessibility guidance (ui-ux-pro-max).
    A stored tube_color/core_color that isn't one of the 12 known cycle
    names (a manual override) falls back to a neutral gray dot rather
    than guessing a hex value.
--}}
@php $groupedByTube = $cores->groupBy('tube_number')->sortKeys(); @endphp
<div class="space-y-1">
    @foreach ($groupedByTube as $tubeNumber => $tubeCores)
        <div class="flex items-center gap-1 flex-wrap">
            <span class="text-[10px] text-gray-400 w-8 shrink-0">T{{ $tubeNumber }}</span>
            @foreach ($tubeCores->sortBy('core_number_in_tube') as $core)
                @php $hex = $colorService->hexForName($core->core_color); @endphp
                <span
                    class="inline-block w-3 h-3 rounded-full border border-gray-300"
                    style="background-color: {{ $hex ?? '#D1D5DB' }};"
                    title="{{ __('Core') }} {{ $core->core_number_in_tube }} — {{ $core->core_color ?? __('tidak diketahui') }} ({{ $core->status->label() }})"
                ></span>
            @endforeach
        </div>
    @endforeach
</div>
