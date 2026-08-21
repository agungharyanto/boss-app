<div class="border border-gray-200 rounded-md p-4">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">{{ __('Traffic') }}</h2>

    @if ($state === 'empty')
        <p class="text-sm text-gray-500">{{ __('Klik salah satu device pada tabel di atas untuk melihat grafik traffic.') }}</p>
    @elseif ($state === 'unavailable')
        <p class="text-sm text-amber-600 italic">{{ __('Data monitoring tidak tersedia.') }}</p>
    @else
        @if (count($availablePorts) > 0)
            <div class="mb-3">
                <label class="text-xs text-gray-500 mr-2">{{ __('Interface') }}</label>
                <select wire:model.live="selectedIfName" class="text-sm rounded-md border-gray-300">
                    @foreach ($availablePorts as $port)
                        <option value="{{ $port['if_name'] }}">
                            {{ $port['if_name'] }}@if ($port['if_oper_status']) ({{ $port['if_oper_status'] }})@endif
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div
            wire:ignore
            x-data="trafficChart(@js($series))"
            x-on:traffic-series-updated.window="update($event.detail)"
        >
            <canvas x-ref="canvas" height="80"></canvas>
        </div>
    @endif
</div>
