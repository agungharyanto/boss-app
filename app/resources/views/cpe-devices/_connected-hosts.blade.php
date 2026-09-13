{{-- v0.12.6 Bagian 3 — "Client Terhubung" diekstrak dari
     _actions-and-history.blade.php supaya bisa ditempatkan terpisah di
     show.blade.php (di bawah WiFi/SSID, kolom kiri) tanpa ikut menyeret
     Riwayat Aksi/tombol aksi yang TETAP di posisi paling bawah halaman.
     detail-row.blade.php (child-row fragment DataTables, deprecated
     UI-wise tapi masih di-test) meng-@include() partial ini secara
     terpisah juga — lihat docblock-nya sendiri. Expects $device,
     $connectedHosts — subset dari kontrak _actions-and-history.blade.php
     yang sudah ada (CpeDeviceDetailController::loadDetailData()). --}}
<div>
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
