<div class="border border-gray-200 rounded-md p-4">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-700">{{ __('Traffic') }}</h2>
        @if ($state === 'ok')
            <button type="button" wire:click="openHistoryModal" class="text-xs text-primary hover:underline">
                {{ __('Riwayat') }}
            </button>
        @endif
    </div>

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

    @if ($showHistoryModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeHistoryModal">
            <div class="bg-white rounded-md p-5 w-full max-w-3xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-sm text-gray-700">{{ __('Riwayat Traffic — :interface', ['interface' => $selectedIfName]) }}</h3>
                    <button type="button" wire:click="closeHistoryModal" class="text-gray-400 hover:text-gray-600 text-sm">&#10005;</button>
                </div>

                @include('livewire.network.partials.history-range-tabs', [
                    'currentRangeValue' => $modalRange,
                    'changeRangeMethod' => 'changeModalRange',
                ])

                @if ($modalState === 'unavailable')
                    <p class="text-sm text-amber-600 italic">{{ __('Data traffic tidak tersedia untuk periode ini.') }}</p>
                @else
                    <div
                        wire:ignore
                        x-data="trafficChart(@js($modalSeries))"
                        x-on:traffic-modal-series-updated.window="update($event.detail)"
                    >
                        <canvas x-ref="canvas" height="140"></canvas>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
