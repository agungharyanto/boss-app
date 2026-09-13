<div>
    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('Grafik Pemakaian') }}</h2>

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
</div>
