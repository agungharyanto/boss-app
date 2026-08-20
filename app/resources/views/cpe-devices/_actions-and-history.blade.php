{{-- Reusable action forms + Riwayat Aksi + Client Terhubung — extracted
     from detail-row.blade.php (2026-08-16) so the same markup/JS-hook
     logic serves both the DataTables child-row fragment AND the standalone
     /cpe-devices/{id} page (show.blade.php) without duplication. Expects
     $device, $canManage, $historyLogs, $connectedHosts — identical
     contract to what CpeDeviceDetailController::loadDetailData() already
     provides both callers.

     "Ganti WiFi"/"Ganti Modem" used to be standalone sections here
     (2026-08-16) — moved out entirely (2026-08-17): WiFi is now per-row
     inline inside show.blade.php's own WiFi/SSID table (one collapse per
     SSID index, since a single flat form could only ever target index 1),
     and Ganti Modem is inline next to the Serial Number row in that same
     page's info panel. This shared partial (still used by the
     superseded-but-still-tested child-row fragment too, which has neither
     a WiFi/SSID table nor a Serial Number row to relocate them into) keeps
     only what's genuinely common to both: Reboot/Remove/Sync, Riwayat
     Aksi, Client Terhubung.

     Reboot/Remove/Sync Sekarang moved to the BOTTOM of this partial
     (2026-08-19, was at the top) — Riwayat Aksi/Client Terhubung are what
     someone actually opens this page to check most of the time; the
     destructive/action buttons reading first, before any of that context,
     was the wrong default. --}}
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
                @if (isset($log->parameters['ssid_index']))
                    <div class="text-xs text-gray-600 mt-1">{{ __('SSID index') }}: {{ $log->parameters['ssid_index'] }}</div>
                @endif
                @if ($log->action_type->value === 'set_ssid' && isset($log->parameters['new_ssid']))
                    <div class="text-xs text-gray-600 mt-1">{{ __('SSID baru') }}: {{ $log->parameters['new_ssid'] }}</div>
                @endif
                @if (($log->parameters['password_changed'] ?? false))
                    <div class="text-xs text-gray-600 mt-1">{{ __('Password diubah') }}</div>
                @endif
                @if ($log->action_type->value === 'set_ssid_enabled' && isset($log->parameters['enabled']))
                    <div class="text-xs text-gray-600 mt-1">{{ $log->parameters['enabled'] ? __('Diaktifkan') : __('Dinonaktifkan') }}</div>
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
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-medium">{{ __('Client Terhubung') }}</h3>
        <label class="flex items-center gap-1.5 text-xs text-gray-500">
            <input type="checkbox" id="cpe-hosts-show-inactive-{{ $device->id }}" onchange="cpeToggleInactiveHosts({{ $device->id }})">
            {{ __('Tampilkan yang tidak aktif juga') }}
        </label>
    </div>
    <p class="text-xs text-gray-500 mb-2">{{ __('Disinkronkan otomatis tiap beberapa menit dari data TR-069 (Hosts) yang sudah tersimpan di GenieACS — bukan snapshot real-time.') }}</p>
    <div class="overflow-x-auto border border-gray-200 rounded-md bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm" id="cpe-hosts-table-{{ $device->id }}">
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
                    {{-- data-active drives the show/hide toggle below — every row
                         stays in the DOM (so the "tampilkan juga" checkbox has real
                         history to reveal), only display:none changes. --}}
                    <tr data-active="{{ $host->is_active ? '1' : '0' }}" @if (! $host->is_active) style="display:none" @endif>
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

@if ($canManage)
    <div class="flex items-center gap-4 text-sm border-t border-gray-200 pt-4">
        <button type="button" onclick="cpeSyncNow({{ $device->id }})" class="text-primary hover:underline">{{ __('Sync Sekarang') }}</button>
        <button type="button" onclick="cpeReboot({{ $device->id }})" class="text-primary hover:underline">{{ __('Reboot') }}</button>
        <button type="button" onclick="cpeRemove({{ $device->id }}, {{ json_encode($device->customer?->name ?? 'pelanggan ini') }})" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
    </div>
@endif
