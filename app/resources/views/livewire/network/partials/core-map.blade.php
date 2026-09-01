{{--
    v0.16.0 Langkah 4 — small colored-dot core map, grouped by tube, one
    row per tube. Text-labeled (tube/core number + color name + status) on
    every dot, not color alone — colored swatches never rely on color to
    convey meaning, per accessibility guidance (ui-ux-pro-max).
    A stored tube_color/core_color that isn't one of the 12 known cycle
    names (a manual override) falls back to a neutral gray dot rather
    than guessing a hex value.

    Re-polish pass (ui-ux-pro-max Skill, same v2.13.0 ruleset): the
    "Compact Label" guidance flags a hover-only `title` as insufficient —
    the label must also reach screen-reader users. Each dot is now
    role="img" with an aria-label mirroring the title, so the core's
    colour + status is exposed without a pointer hover.
--}}
@php $groupedByTube = $cores->groupBy('tube_number')->sortKeys(); @endphp
<div class="space-y-1">
    @foreach ($groupedByTube as $tubeNumber => $tubeCores)
        <div class="flex items-center gap-1 flex-wrap">
            <span class="text-[10px] text-gray-400 w-8 shrink-0">T{{ $tubeNumber }}</span>
            @foreach ($tubeCores->sortBy('core_number_in_tube') as $core)
                @php
                    $hex = $colorService->hexForName($core->core_color);
                    $coreLabel = __('Core').' '.$core->core_number_in_tube.' — '.($core->core_color ?? __('tidak diketahui')).' ('.$core->status->label().')';
                @endphp
                <span
                    class="inline-block w-3 h-3 rounded-full border border-gray-300"
                    style="background-color: {{ $hex ?? '#D1D5DB' }};"
                    role="img"
                    aria-label="{{ $coreLabel }}"
                    title="{{ $coreLabel }}"
                ></span>
            @endforeach
        </div>
    @endforeach
</div>
