@php
    $cellBadge = function (array $cell, string $suffix = '', int $decimals = 1) {
        return match ($cell['state']) {
            'ok' => ['text' => number_format($cell['value'], $decimals).$suffix, 'class' => 'text-gray-800'],
            'no_sensor' => ['text' => __('Tidak ada sensor'), 'class' => 'text-gray-400 italic'],
            'unavailable' => ['text' => __('Data tidak tersedia'), 'class' => 'text-amber-600 italic'],
            default => ['text' => '-', 'class' => 'text-gray-400'],
        };
    };
@endphp

<div class="border border-gray-200 rounded-md overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">{{ __('Device') }}</h2>
        <button type="button" wire:click="loadDevices" class="text-xs text-primary hover:underline">
            {{ __('Muat Ulang') }}
        </button>
    </div>

    @if ($removeErrorMessage)
        <p class="px-4 py-2 text-xs text-red-600 bg-red-50 border-b border-gray-100">{{ $removeErrorMessage }}</p>
    @endif

    @if ($pageUnavailable)
        <div class="p-6 text-center text-sm text-amber-700 bg-amber-50">
            {{ __('Data monitoring tidak tersedia — LibreNMS tidak bisa dihubungi. Coba muat ulang beberapa saat lagi.') }}
        </div>
    @elseif (empty($rows))
        <div class="p-6 text-center text-sm text-gray-500">
            {{ __('Tidak ada device.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Nama') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Uptime') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('CPU%') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Memory%') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Temperature') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Availability% (1 hari)') }}</th>
                        <th class="px-4 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rows as $row)
                        @php
                            $cpu = $cellBadge($row['cpu'], '%');
                            $memory = $cellBadge($row['memory'], '%');
                            $temperature = $cellBadge($row['temperature'], '°C');
                            $availability = $cellBadge($row['availability'], '%', 2);
                        @endphp
                        <tr
                            wire:click="selectDevice({{ $row['device_id'] }})"
                            wire:key="device-row-{{ $row['device_id'] }}"
                            class="cursor-pointer hover:bg-gray-50 {{ $selectedDeviceId === $row['device_id'] ? 'bg-primary/5' : '' }}"
                        >
                            <td class="px-4 py-2 font-medium text-gray-800">{{ $row['name'] }}</td>
                            <td class="px-4 py-2">
                                @if ($row['status'])
                                    <span class="inline-flex items-center gap-1 text-green-700">
                                        <span class="w-2 h-2 rounded-full bg-green-500"></span> {{ __('Online') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-red-700">
                                        <span class="w-2 h-2 rounded-full bg-red-500"></span> {{ __('Offline') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-600">
                                {{ $row['uptime'] !== null ? \Carbon\CarbonInterval::seconds($row['uptime'])->cascade()->forHumans(['short' => true]) : '-' }}
                            </td>
                            <td class="px-4 py-2 {{ $cpu['class'] }}">{{ $cpu['text'] }}</td>
                            <td class="px-4 py-2 {{ $memory['class'] }}">{{ $memory['text'] }}</td>
                            <td class="px-4 py-2 {{ $temperature['class'] }}">{{ $temperature['text'] }}</td>
                            <td class="px-4 py-2 {{ $availability['class'] }}">{{ $availability['text'] }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click.stop="openHistory({{ $row['device_id'] }}, '{{ addslashes($row['name']) }}')"
                                    class="text-xs text-primary hover:underline"
                                >
                                    {{ __('Riwayat') }}
                                </button>
                                @can('monitoring.manage')
                                    <button
                                        type="button"
                                        wire:click.stop="openEdit({{ $row['device_id'] }})"
                                        class="text-xs text-primary hover:underline ml-2"
                                    >
                                        {{ __('Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click.stop="removeDevice({{ $row['device_id'] }})"
                                        wire:confirm="{{ __('Hapus device \":name\" dari LibreNMS? Riwayat CPU/Memory/Traffic-nya ikut terhapus dan TIDAK bisa dikembalikan.', ['name' => $row['name']]) }}"
                                        class="text-xs text-red-600 hover:underline ml-2"
                                    >
                                        {{ __('Hapus') }}
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100">
            {{ __('Klik baris untuk lihat grafik traffic device tersebut.') }}
        </p>
    @endif
</div>
