@php
    $metrics = [
        'cpu' => __('CPU'),
        'memory' => __('Memory'),
        'temperature' => __('Suhu'),
    ];
@endphp

<div>
    @if ($showModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeModal">
            <div class="bg-white rounded-md p-5 w-full max-w-3xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-sm text-gray-700">{{ __('Riwayat — :name', ['name' => $deviceName]) }}</h3>
                    <button type="button" wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-sm">&#10005;</button>
                </div>

                <div class="flex items-center gap-1" role="tablist" aria-label="{{ __('Metrik') }}">
                    @foreach ($metrics as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $metric === $key ? 'true' : 'false' }}"
                            wire:click="changeMetric('{{ $key }}')"
                            class="px-3 py-1 text-xs rounded-md border {{ $metric === $key ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @include('livewire.network.partials.history-range-tabs', [
                    'currentRangeValue' => $range,
                    'changeRangeMethod' => 'changeRange',
                ])

                @if ($state === 'no_sensor')
                    <p class="text-sm text-gray-500 italic">{{ __('Device ini tidak punya sensor :metric.', ['metric' => $metrics[$metric]]) }}</p>
                @elseif ($state === 'unavailable')
                    <p class="text-sm text-amber-600 italic">{{ __('Data riwayat tidak tersedia — coba lagi beberapa saat.') }}</p>
                @else
                    <div
                        wire:ignore
                        x-data="deviceHistoryChart(@js($series), @js($this->metricUnit()))"
                        x-on:device-history-series-updated.window="update($event.detail)"
                    >
                        <canvas x-ref="canvas" height="140"></canvas>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
