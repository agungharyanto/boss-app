<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('web.olt-devices.index') }}" class="text-xs text-primary hover:underline">&larr; {{ __('Daftar OLT') }}</a>
            <h1 class="text-lg font-semibold text-gray-800">{{ $oltDevice->name }}</h1>
            <p class="text-xs text-gray-500">{{ $oltDevice->ip_address }} &middot; {{ $oltDevice->nas?->name }}</p>
        </div>
    </div>

    {{-- Tab nav (gaya SmartOLT). Hanya "Uplink" aktif sprint ini. --}}
    <div class="border-b border-gray-200 overflow-x-auto">
        <nav class="flex gap-1 text-sm">
            @foreach ($this->tabs() as $tab)
                @if ($tab['enabled'])
                    <button wire:click="selectTab('{{ $tab['key'] }}')"
                        class="px-3 py-2 border-b-2 whitespace-nowrap {{ $activeTab === $tab['key'] ? 'border-primary text-primary font-medium' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        {{ $tab['label'] }}
                    </button>
                @else
                    <span class="px-3 py-2 border-b-2 border-transparent text-gray-300 whitespace-nowrap cursor-not-allowed"
                        title="{{ __('Segera hadir') }}">
                        {{ $tab['label'] }}
                        <span class="ml-1 text-[10px] uppercase tracking-wide bg-gray-100 text-gray-400 rounded px-1">{{ __('Segera hadir') }}</span>
                    </span>
                @endif
            @endforeach
        </nav>
    </div>

    @if ($activeTab === 'uplink')
        <div class="space-y-3">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="text-xs text-gray-500">
                    @php($first = $this->uplinkPorts->first())
                    @if ($first?->last_synced_at)
                        {{ __('Terakhir di-refresh') }}: {{ $first->last_synced_at->diffForHumans() }}
                    @else
                        {{ __('Belum pernah di-refresh — klik tombol di kanan.') }}
                    @endif
                </div>
                <button wire:click="refreshUplink" wire:loading.attr="disabled" wire:target="refreshUplink"
                    class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                    <span wire:loading.remove wire:target="refreshUplink">{{ __('Refresh info port uplink') }}</span>
                    <span wire:loading wire:target="refreshUplink">{{ __('Membaca OLT… (bisa ~1-2 menit)') }}</span>
                </button>
            </div>

            @if ($refreshError)
                <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">{{ $refreshError }}</div>
            @endif
            @if ($refreshNotice)
                <div class="text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{{ $refreshNotice }}</div>
            @endif

            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="min-w-full text-xs divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-gray-500 uppercase">
                        <tr>
                            <th class="px-2 py-1.5 text-left">{{ __('Uplink port') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Description') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Type') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Admin') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Status') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Negotiation') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('MTU') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('WaveL') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Signal (dBm)') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Temp') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('PVID') }}</th>
                            <th class="px-2 py-1.5 text-left">{{ __('Mode: tagged VLANs') }}</th>
                            <th class="px-2 py-1.5 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($this->uplinkPorts as $port)
                            <tr wire:key="uplink-{{ $port->id }}">
                                <td class="px-2 py-1.5 font-mono">{{ $port->port_name }}@if($port->is_10g)<span class="ml-1 text-[10px] bg-indigo-100 text-indigo-700 rounded px-1">10G</span>@endif</td>
                                <td class="px-2 py-1.5">{{ $port->description ?? '-' }}</td>
                                <td class="px-2 py-1.5">{{ $port->port_type ?? '-' }}</td>
                                <td class="px-2 py-1.5">
                                    <span class="px-1.5 py-0.5 rounded {{ $port->admin_state === 'up' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $port->admin_state ?? '-' }}</span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <span class="px-1.5 py-0.5 rounded {{ $port->oper_status === 'up' ? 'bg-green-100 text-green-700' : 'bg-red-50 text-red-600' }}">{{ $port->oper_status ?? '-' }}</span>
                                </td>
                                <td class="px-2 py-1.5">{{ $port->negotiation ?? '-' }}</td>
                                <td class="px-2 py-1.5">{{ $port->mtu ?? '-' }}</td>
                                <td class="px-2 py-1.5">{{ $port->wavelength_nm ? $port->wavelength_nm.' nm' : '-' }}</td>
                                <td class="px-2 py-1.5">{{ $port->rx_power_dbm !== null ? 'Rx '.$port->rx_power_dbm : '-' }}{{ $port->tx_power_dbm !== null ? ' / Tx '.$port->tx_power_dbm : '' }}</td>
                                <td class="px-2 py-1.5">{{ $port->temperature_c !== null ? $port->temperature_c.'°C' : '-' }}</td>
                                <td class="px-2 py-1.5">{{ $port->pvid ?? '-' }}</td>
                                <td class="px-2 py-1.5">
                                    @if ($port->tagged_vlans)
                                        <span class="text-gray-400">{{ $port->port_mode }}:</span> <span class="font-mono">{{ $port->tagged_vlans }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-right">
                                    <span class="text-gray-300 cursor-not-allowed" title="{{ __('Segera hadir') }}">{{ __('Configure') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-2 py-4 text-center text-gray-500">
                                    {{ __('Belum ada data port uplink. Klik "Refresh info port uplink".') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-gray-400">{{ __('Data read-only dari OLT. Tombol "Configure" belum aktif (tidak ada tulis ke OLT di sprint ini).') }}</p>
        </div>
    @endif
</div>
