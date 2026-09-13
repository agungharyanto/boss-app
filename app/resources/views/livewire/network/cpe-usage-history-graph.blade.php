<div>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">{{ __('Grafik Pemakaian') }}</h2>
        {{-- v0.12.6 (revisi) — tombol "Riwayat" HANYA saat state === 'ok',
             beda dari RX Power yang tombolnya selalu ada — lihat komponen
             ini sendiri untuk alasannya. --}}
        @if ($state === 'ok')
            <button type="button" wire:click="openHistoryModal" class="text-xs text-primary hover:underline">
                {{ __('Riwayat') }}
            </button>
        @endif
    </div>

    @if ($state === 'no_data')
        <p class="text-sm text-gray-500">{{ __('Belum ada data pemakaian — akun ini belum aktif tercatat lewat FreeRADIUS boss-app.') }}</p>
    @else
        <div
            wire:ignore
            x-data="usageHistoryChart(@js($series))"
            x-on:usage-history-series-updated.window="update($event.detail)"
        >
            <canvas x-ref="canvas" height="80"></canvas>
        </div>
    @endif

    @if ($showHistoryModal)
        <x-modal wire:click.self="closeHistoryModal" max-width="max-w-3xl" panel-class="rounded-md p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-medium text-sm text-gray-700">{{ __('Riwayat Pemakaian') }}</h3>
                <button type="button" wire:click="closeHistoryModal" class="text-gray-400 hover:text-gray-600 text-sm">&#10005;</button>
            </div>

            @include('livewire.network.partials.history-range-tabs', [
                'currentRangeValue' => $modalRange,
                'changeRangeMethod' => 'changeModalRange',
            ])

            @if ($modalState === 'no_data')
                <p class="text-sm text-gray-500">{{ __('Belum ada data pemakaian — akun ini belum aktif tercatat lewat FreeRADIUS boss-app.') }}</p>
            @else
                <div
                    wire:ignore
                    x-data="usageHistoryChart(@js($modalSeries))"
                    x-on:usage-history-modal-series-updated.window="update($event.detail)"
                >
                    <canvas x-ref="canvas" height="140"></canvas>
                </div>
            @endif
        </x-modal>
    @endif
</div>
