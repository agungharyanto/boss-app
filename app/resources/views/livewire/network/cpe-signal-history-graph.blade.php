<div>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">{{ __('RX Power') }}</h2>
        <button type="button" wire:click="openHistoryModal" class="text-xs text-primary hover:underline">
            {{ __('Riwayat') }}
        </button>
    </div>

    @if ($state === 'no_history')
        <p class="text-sm text-gray-500">{{ __('Belum ada data histori sinyal.') }}</p>
    @elseif ($state === 'all_null')
        <p class="text-sm text-amber-600 italic">{{ __('Sinyal tidak terbaca dalam periode ini.') }}</p>
    @else
        <div
            wire:ignore
            x-data="signalHistoryChart(@js($series))"
            x-on:signal-history-series-updated.window="update($event.detail)"
        >
            <canvas x-ref="canvas" height="80"></canvas>
        </div>
    @endif

    @if ($showHistoryModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeHistoryModal">
            <div class="bg-white rounded-md p-5 w-full max-w-3xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-sm text-gray-700">{{ __('Riwayat RX Power') }}</h3>
                    <button type="button" wire:click="closeHistoryModal" class="text-gray-400 hover:text-gray-600 text-sm">&#10005;</button>
                </div>

                @include('livewire.network.partials.history-range-tabs', [
                    'currentRangeValue' => $modalRange,
                    'changeRangeMethod' => 'changeModalRange',
                ])

                @php $periodLabel = $customRangeMode ? __('Custom') : $selectedModalRange->label(); @endphp

                @if ($modalState === 'no_history')
                    <p class="text-sm text-gray-500">{{ __('Belum ada data histori sinyal untuk periode :range.', ['range' => $periodLabel]) }}</p>
                @elseif ($modalState === 'all_null')
                    <p class="text-sm text-amber-600 italic">{{ __('Sinyal tidak terbaca dalam periode :range.', ['range' => $periodLabel]) }}</p>
                @else
                    <div
                        wire:ignore
                        x-data="signalHistoryChart(@js($modalSeries))"
                        x-on:signal-history-modal-series-updated.window="update($event.detail)"
                    >
                        <canvas x-ref="canvas" height="140"></canvas>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
