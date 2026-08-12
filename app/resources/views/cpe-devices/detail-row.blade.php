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

    @if ($canManage)
        <div class="flex items-center gap-4 text-sm border-t border-gray-200 pt-4">
            <button type="button" onclick="cpeReboot({{ $device->id }})" class="text-primary hover:underline">{{ __('Reboot') }}</button>
            <button type="button" onclick="cpeRemove({{ $device->id }}, {{ json_encode($device->customer?->name ?? 'pelanggan ini') }})" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
        </div>

        <div class="border-t border-gray-200 pt-4 space-y-3">
            <h3 class="text-sm font-medium">{{ __('Ganti WiFi') }}</h3>
            <p class="text-xs text-gray-500">{{ __('Isi salah satu atau keduanya. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil).') }}</p>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('SSID Baru') }}</label>
                <input type="text" id="cpe-wifi-ssid-{{ $device->id }}" maxlength="32" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('Password Baru') }}</label>
                <input type="password" id="cpe-wifi-password-{{ $device->id }}" maxlength="63" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">{{ __('8-63 karakter (standar WPA-PSK).') }}</p>
            </div>
            <div class="text-xs text-red-600" id="cpe-wifi-error-{{ $device->id }}"></div>
            <button type="button" onclick="cpeSubmitWifi({{ $device->id }})" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">{{ __('Kirim Perintah') }}</button>
        </div>

        <div class="border-t border-gray-200 pt-4 space-y-3">
            <h3 class="text-sm font-medium">{{ __('Ganti Modem') }}</h3>
            <p class="text-xs text-gray-500">{{ __('Untuk pergantian perangkat fisik pelanggan ini. Binding lama dihapus (tanpa dicatat sebagai penolakan) dan device baru dicari di GenieACS berdasarkan serial number.') }}</p>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('Serial Number Baru') }}</label>
                <input type="text" id="cpe-replacement-serial-{{ $device->id }}" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
            </div>
            <div class="text-xs text-red-600" id="cpe-replace-error-{{ $device->id }}"></div>
            <button type="button" onclick="cpeReplaceModem({{ $device->id }}, {{ json_encode($device->serial_number) }})" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">{{ __('Ganti Modem') }}</button>
        </div>
    @endif

    <div class="border-t border-gray-200 pt-4">
        <h3 class="text-sm font-medium mb-1">{{ __('Riwayat Aksi') }}</h3>
        <p class="text-xs text-gray-500 mb-2">{{ __('"Terkirim ke GenieACS" berarti perintah berhasil masuk antrean — BUKAN konfirmasi perangkat sudah menjalankannya.') }}</p>
        <div class="divide-y divide-gray-100">
            @forelse ($historyLogs as $log)
                <div class="py-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $log->action_type->label() }}</span>
                        @php
                            $logStatusColor = match ($log->status->value) {
                                'delivered' => 'bg-blue-100 text-blue-700',
                                'failed' => 'bg-red-100 text-red-700',
                                default => 'bg-yellow-100 text-yellow-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded-full text-xs {{ $logStatusColor }}">{{ $log->status->label() }}</span>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">
                        {{ $log->created_at->diffForHumans() }} · {{ __('oleh') }}
                        {{ $log->performed_by === null ? __('Sistem (auto-provisioning)') : ($log->performedBy?->name ?? '—') }}
                    </div>
                    @if ($log->action_type->value === 'set_ssid' && isset($log->parameters['new_ssid']))
                        <div class="text-xs text-gray-600 mt-1">{{ __('SSID baru') }}: {{ $log->parameters['new_ssid'] }}</div>
                    @endif
                    @if (($log->parameters['password_changed'] ?? false))
                        <div class="text-xs text-gray-600 mt-1">{{ __('Password diubah') }}</div>
                    @endif
                    @if ($log->status->value === 'failed' && $log->failed_reason)
                        <div class="text-xs text-red-600 mt-1">{{ $log->failed_reason }}</div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500 py-4">{{ __('Belum ada aksi tercatat untuk perangkat ini.') }}</p>
            @endforelse
        </div>
    </div>

    <div class="border-t border-gray-200 pt-4">
        <h3 class="text-sm font-medium mb-2">{{ __('Client Terhubung') }}</h3>
        <p class="text-xs text-gray-500 mb-2">{{ __('Disinkronkan otomatis tiap beberapa menit dari data TR-069 (Hosts) yang sudah tersimpan di GenieACS — bukan snapshot real-time.') }}</p>
        <div class="overflow-x-auto border border-gray-200 rounded-md bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Hostname') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('MAC') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('IP') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Terakhir Terlihat') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse ($connectedHosts as $host)
                        <tr>
                            <td class="px-3 py-2 text-gray-800">{{ $host->hostname ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600 font-mono text-xs">{{ $host->mac_address }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $host->ip_address ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $host->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $host->is_active ? __('Aktif') : __('Tidak Aktif') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">{{ $host->last_seen_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">{{ __('Belum ada client tercatat untuk perangkat ini.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
