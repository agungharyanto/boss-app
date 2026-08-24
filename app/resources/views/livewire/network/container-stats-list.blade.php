@php
    $formatBytes = function (?int $bytes) {
        if ($bytes === null) {
            return '-';
        }
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    };
@endphp

<div class="border border-gray-200 rounded-md overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-gray-700">{{ __('Container BOSS App') }}</h2>
            <p class="text-xs text-gray-400 mt-0.5">
                {{ __('Snapshot terakhir, disinkronkan setiap 5 menit via docker-stats-proxy.') }}
            </p>
        </div>
        <button type="button" wire:click="loadStats" class="text-xs text-primary hover:underline">
            {{ __('Muat Ulang') }}
        </button>
    </div>

    @if ($noData)
        <div class="p-6 text-center text-sm text-gray-500">
            {{ __('Belum ada data — menunggu siklus sinkronisasi pertama (infra:sync-container-stats).') }}
        </div>
    @else
        {{-- v0.8.3 — grouped into VPN/LibreNMS/BOSS App Core/Lainnya
             (App\Services\Infra\ContainerStatsService::GROUP_ORDER), each a
             collapsible section reusing the sidebar's own sub-group
             expand/collapse idiom (own localStorage key so the state
             persists across page loads, same as sidebar.blade.php v0.8.1). --}}
        @foreach ($groupedRows as $group => $groupRows)
            <div
                x-data="{ open: localStorage.getItem('container-group-{{ Str::slug($group) }}') !== 'false' }"
                class="border-b border-gray-100 last:border-b-0"
                wire:key="container-group-{{ Str::slug($group) }}"
            >
                <button
                    type="button"
                    x-on:click="open = !open; localStorage.setItem('container-group-{{ Str::slug($group) }}', open)"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="container-group-body-{{ Str::slug($group) }}"
                    class="w-full flex items-center gap-2 px-4 py-2 bg-gray-50/60 hover:bg-gray-100 text-left"
                >
                    <svg x-bind:class="open ? 'rotate-90' : ''" class="w-3.5 h-3.5 text-gray-400 transition-transform shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">{{ $group }}</span>
                    <span class="text-xs text-gray-400">({{ count($groupRows) }})</span>
                </button>

                <div id="container-group-body-{{ Str::slug($group) }}" x-show="open" x-transition class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('Nama Container') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('CPU%') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Memory') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Network RX') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Network TX') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Disk (writable layer)') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($groupRows as $row)
                                <tr wire:key="container-row-{{ $row['container_name'] }}">
                                    <td class="px-4 py-2 font-medium text-gray-800">{{ $row['container_name'] }}</td>
                                    <td class="px-4 py-2 text-gray-600">
                                        {{ $row['cpu_percent'] !== null ? number_format($row['cpu_percent'], 2).'%' : '-' }}
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">
                                        @if ($row['memory_usage_mb'] !== null)
                                            {{ number_format($row['memory_usage_mb'], 1) }} MB
                                            @if ($row['memory_limit_mb'])
                                                <span class="text-gray-400">/ {{ number_format($row['memory_limit_mb'] / 1024, 1) }} GB</span>
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">{{ $formatBytes($row['network_rx_bytes']) }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $formatBytes($row['network_tx_bytes']) }}</td>
                                    <td class="px-4 py-2 text-gray-600">
                                        {{ $row['disk_usage_mb'] !== null ? number_format($row['disk_usage_mb'], 1).' MB' : '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
        <p class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100">
            {{ __('Terakhir disinkronkan: ') }}{{ $rows[0]['recorded_at']?->diffForHumans() ?? '-' }}
        </p>
    @endif
</div>
