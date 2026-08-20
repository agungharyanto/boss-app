{{-- Rendered server-side, injected as a DataTables child row's content by
     resources/views/livewire/network/cpe-device-index.blade.php's JS (see
     cpeOpenDetail()). Deliberately plain HTML + onclick="" calling global
     JS functions (cpeReboot()/cpeSubmitWifi()/cpeRemove()/
     cpeReplaceModem()) defined once in the parent page — event delegation
     would also work, but inline handlers need no re-binding step after
     jQuery injects this fragment into a brand-new DOM node. --}}
<div class="p-4 bg-gray-50 space-y-6" data-cpe-detail-id="{{ $device->id }}">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Pelanggan') }}</div>
            <div class="text-gray-800">{{ $device->customer?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Manufacturer / Model') }}</div>
            <div class="text-gray-800">{{ $device->manufacturer ?? '—' }} {{ $device->model_name }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Serial Number') }}</div>
            <div class="text-gray-800 font-mono">{{ $device->serial_number }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('MAC Address') }}</div>
            <div class="text-gray-800 font-mono">{{ $summary['mac_address'] ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Status') }}</div>
            @php
                $statusColor = match ($device->status->value) {
                    'online' => 'bg-green-100 text-green-700',
                    'offline' => 'bg-red-100 text-red-700',
                    default => 'bg-yellow-100 text-yellow-700',
                };
            @endphp
            <span class="px-2 py-0.5 rounded-full text-xs {{ $statusColor }}">{{ $device->status->label() }}</span>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('RX Power') }}</div>
            <div class="text-gray-800">{{ $summary['rx_power_dbm'] !== null ? number_format($summary['rx_power_dbm'], 2).' dBm' : '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('TX Power') }}</div>
            <div class="text-gray-800">{{ $summary['tx_power_dbm'] !== null ? number_format($summary['tx_power_dbm'], 2).' dBm' : '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Online Duration') }}</div>
            <div class="text-gray-800">{{ $device->status->value === 'online' && $device->status_changed_at ? $device->status_changed_at->diffForHumans(null, true) : '-' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 uppercase">{{ __('Uptime Modem') }}</div>
            <div class="text-gray-800">
                @php $uptimeSeconds = $summary['device_uptime_seconds'] ?? null; @endphp
                @if ($uptimeSeconds === null)
                    -
                @else
                    @php
                        $totalMinutes = (int) floor($uptimeSeconds / 60);
                        $days = intdiv($totalMinutes, 1440);
                        $hours = intdiv($totalMinutes % 1440, 60);
                        $minutes = $totalMinutes % 60;
                    @endphp
                    {{ $days > 0 ? "{$days}h {$hours}j" : "{$hours}j {$minutes}m" }}
                @endif
            </div>
        </div>
    </div>

    @include('cpe-devices._actions-and-history', ['device' => $device, 'canManage' => $canManage, 'historyLogs' => $historyLogs, 'connectedHosts' => $connectedHosts])
</div>
