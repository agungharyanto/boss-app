{{-- Standalone CPE detail page (2026-08-16) — replaces the DataTables
     child-row expand interaction. Layout adapted from a SmartOLT reference
     screenshot but filled ONLY with fields this app actually has from
     TR-069/GenieACS — OLT/Board/Port/GPON-channel/Zone/Splitter etc. are
     OMCI/OLT-level data this app has no source for at all, so that whole
     section is simply omitted rather than shown with dummy/placeholder
     values. Action forms/Riwayat Aksi/Client Terhubung are
     @include()'d from _actions-and-history.blade.php, shared verbatim
     with the (still-existing, now UI-unused-but-still-tested) DataTables
     child-row fragment — see CpeDeviceDetailController's own docblock.

     2026-08-17: per-SSID "Ganti WiFi"/"Ganti Modem" collapses (Alpine
     x-show, no Livewire round-trip needed just to open/close a form) —
     [x-cloak] has no CSS rule ANYWHERE else in this app (checked before
     adding), so it's defined here, scoped to this page, to avoid a brief
     flash-of-unstyled-content before Alpine hides a closed collapse on
     first paint. --}}
@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

<div class="p-6 w-full max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('web.cpe-devices.index') }}" class="text-sm text-primary hover:underline">&larr; {{ __('Kembali ke daftar') }}</a>
            <h1 class="text-2xl font-semibold text-gray-800 mt-1">{{ __('Detail Perangkat CPE') }}</h1>
        </div>
        @php
            $statusColor = match ($device->status->value) {
                'online' => 'bg-green-100 text-green-700',
                'offline' => 'bg-red-100 text-red-700',
                default => 'bg-yellow-100 text-yellow-700',
            };
        @endphp
        <span class="px-3 py-1 rounded-full text-sm {{ $statusColor }}">{{ $device->status->label() }}</span>
    </div>

    <div id="cpe-flash" class="mb-4 text-sm hidden"></div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Panel kiri — identitas & status dasar, semua field ini KITA
             benar-benar punya (bukan OLT/OMCI-level).

             x-data here scopes the "Ganti Modem" collapse (2026-08-17,
             moved from its own standalone section below to inline next to
             Serial Number) — a single toggle is enough since only one
             device row exists on this card, unlike the WiFi/SSID table
             below where each row needs independent state. --}}
        <div class="bg-white border border-gray-200 rounded-md p-5 space-y-4" x-data="{ showReplaceModem: false }">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">{{ __('Informasi Perangkat') }}</h2>

            <dl class="grid grid-cols-2 gap-y-3 text-sm">
                <dt class="text-gray-500">{{ __('Serial Number') }}</dt>
                <dd class="font-mono text-gray-800">
                    {{ $device->serial_number }}
                    @if ($canManage)
                        <button type="button" @click="showReplaceModem = !showReplaceModem" class="font-sans text-primary hover:underline text-xs ml-2" x-text="showReplaceModem ? '{{ __('Batal') }}' : '{{ __('Ganti Modem') }}'"></button>
                    @endif
                </dd>

                <dt class="text-gray-500">{{ __('Manufacturer / Model') }}</dt>
                <dd class="text-gray-800">{{ $device->manufacturer ?? '—' }} {{ $device->model_name }}</dd>

                <dt class="text-gray-500">{{ __('Pelanggan') }}</dt>
                <dd class="text-gray-800">
                    @if ($device->customer)
                        <a href="{{ route('web.customers.show', $device->customer) }}" class="text-primary hover:underline">{{ $device->customer->name }}</a>
                    @else
                        —
                    @endif
                </dd>

                <dt class="text-gray-500">{{ __('Alamat') }}</dt>
                <dd class="text-gray-800">{{ $device->customer?->address ?? '-' }}</dd>

                <dt class="text-gray-500">{{ __('Online Duration') }}</dt>
                <dd class="text-gray-800">{{ $device->status->value === 'online' && $device->status_changed_at ? $device->status_changed_at->diffForHumans(null, true) : '-' }}</dd>

                <dt class="text-gray-500">{{ __('Uptime Modem') }}</dt>
                <dd class="text-gray-800">
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
                </dd>

                {{-- "Authorization date" analog per SmartOLT reference — cpe_devices.created_at
                     is the closest thing this app has (the moment this row was bound). --}}
                <dt class="text-gray-500">{{ __('Tanggal Binding') }}</dt>
                <dd class="text-gray-800">{{ $device->created_at->format('d M Y H:i') }}</dd>
            </dl>

            @if ($canManage)
                <div x-show="showReplaceModem" x-cloak class="border-t border-gray-200 pt-4 space-y-3">
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

            {{-- OLT/Board/Port/GPON channel/Configuration Preset/Zone/Splitter — deliberately
                 not rendered at all. This app has zero source for OLT/OMCI-level data (that
                 lives in SmartOLT, a separate system this module doesn't integrate with) —
                 no dummy/placeholder row, no empty section header. --}}
        </div>

        {{-- Panel kanan — status jaringan/sinyal dari TR-069. --}}
        <div class="bg-white border border-gray-200 rounded-md p-5 space-y-4">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">{{ __('Status Jaringan') }}</h2>

            <dl class="grid grid-cols-2 gap-y-3 text-sm">
                <dt class="text-gray-500">{{ __('RX Power') }}</dt>
                <dd class="text-gray-800">{{ $summary['rx_power_dbm'] !== null ? number_format($summary['rx_power_dbm'], 2).' dBm' : '-' }}</dd>

                <dt class="text-gray-500">{{ __('TX Power') }}</dt>
                <dd class="text-gray-800">{{ $summary['tx_power_dbm'] !== null ? number_format($summary['tx_power_dbm'], 2).' dBm' : '-' }}</dd>

                <dt class="text-gray-500">{{ __('MAC Address') }}</dt>
                <dd class="font-mono text-gray-800">{{ $summary['mac_address'] ?? '-' }}</dd>

                <dt class="text-gray-500">{{ __('PPPoE Username') }}</dt>
                <dd class="font-mono text-gray-800">{{ $pppoeUsername ?? '-' }}</dd>

                @if ($pppoeUsername !== null)
                    <dt class="text-gray-500">{{ __('PPPoE Password') }}</dt>
                    <dd class="text-gray-800">
                        <span id="cpe-pppoe-password-masked-{{ $device->id }}">••••••••</span>
                        <span id="cpe-pppoe-password-value-{{ $device->id }}" class="font-mono hidden"></span>
                        <button type="button" onclick="cpeRevealPppoePassword({{ $device->id }})" id="cpe-pppoe-reveal-btn-{{ $device->id }}" class="text-primary hover:underline text-xs ml-2">{{ __('Tampilkan') }}</button>
                    </dd>
                @endif
            </dl>

            <div>
                <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">{{ __('Attached VLANs') }}</h3>
                @if (count($wanConnections) > 0)
                    <div class="overflow-x-auto border border-gray-200 rounded-md">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                                <tr>
                                    <th class="px-3 py-1.5 text-left">{{ __('Nama Koneksi') }}</th>
                                    <th class="px-3 py-1.5 text-left">{{ __('Tipe') }}</th>
                                    <th class="px-3 py-1.5 text-left">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($wanConnections as $conn)
                                    <tr>
                                        <td class="px-3 py-1.5 font-mono text-xs">{{ $conn['name'] }}</td>
                                        <td class="px-3 py-1.5">{{ $conn['type'] }}</td>
                                        <td class="px-3 py-1.5">{{ $conn['status'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">-</p>
                @endif
            </div>
        </div>
    </div>

    {{-- v0.8.3 — full-width RX Power graph section, moved out of the
         "Status Jaringan" panel (which now only holds instant-reading
         fields) so the graph gets the full content width instead of being
         squeezed into one column. Title/"Riwayat" link live INSIDE
         CpeSignalHistoryGraph's own view (same "component owns its own
         header" convention DeviceTrafficGraph already uses), not
         duplicated here — see CLAUDE.md's "RX Power History (v0.8.3)" and
         the component's own docblock. This component authorizes itself
         independently (defense in depth), not relying solely on this
         controller's own earlier $this->authorize('view', $cpeDevice). --}}
    <div class="bg-white border border-gray-200 rounded-md p-5 mt-6">
        @livewire('network.cpe-signal-history-graph', ['cpeDeviceId' => $device->id], key('cpe-signal-history-graph-'.$device->id))
    </div>

    {{-- v0.8.4 — "Riwayat Dialup" (radacct via RadiusSessionHistoryService),
         same full-width standalone-section placement as the RX Power graph
         above — a separate concern (RADIUS accounting, not GenieACS/SNMP),
         so its own component/section rather than folded into either
         existing panel. --}}
    <div class="bg-white border border-gray-200 rounded-md p-5 mt-6">
        @livewire('network.cpe-dialup-history', ['cpeDeviceId' => $device->id], key('cpe-dialup-history-'.$device->id))
    </div>

    {{-- WiFi/SSID — semua SSID yang ditemukan discovery, bukan cuma SSID1.
         "Ganti WiFi" (2026-08-17) is now per-row here instead of one
         standalone form below — a single flat form could only ever target
         SSID index 1, but this table can have several real indices (see
         CpeParameterResolverService::resolveWlanConfigurations()). Each row
         gets its OWN <tbody x-data="{open:false}"> (valid HTML — a <table>
         may contain multiple <tbody> elements) so the collapse state for
         one SSID's edit form is fully independent of every other row's —
         a single shared x-data on the outer <table>/<tbody> would have
         opened/closed every row's form together. --}}
    <div class="bg-white border border-gray-200 rounded-md p-5 mt-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('WiFi / SSID') }}</h2>
        @if (count($wlanConfigurations) > 0)
            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-1.5 text-left">{{ __('Index') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('SSID') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('Status') }}</th>
                            @if ($canManage)
                                <th class="px-3 py-1.5 text-left">{{ __('Aksi') }}</th>
                            @endif
                        </tr>
                    </thead>
                    @foreach ($wlanConfigurations as $wlan)
                        <tbody class="divide-y divide-gray-100" x-data="{ open: false }">
                            <tr @if ($wlan['ssid'] === null) class="text-gray-400" @endif>
                                <td class="px-3 py-1.5">{{ $wlan['index'] }}</td>
                                <td class="px-3 py-1.5">{{ $wlan['ssid'] ?? __('(kosong)') }}</td>
                                <td class="px-3 py-1.5">
                                    @if ($wlan['enabled'] === true)
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">{{ __('Aktif') }}</span>
                                    @elseif ($wlan['enabled'] === false)
                                        <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">{{ __('Nonaktif') }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <button type="button" @click="open = !open" class="text-primary hover:underline text-xs" x-text="open ? '{{ __('Tutup') }}' : '{{ __('Edit') }}'"></button>
                                        <button type="button" onclick="cpeToggleSsid({{ $device->id }}, {{ (int) $wlan['index'] }}, {{ $wlan['enabled'] === true ? 'true' : 'false' }})" class="text-xs ml-2 hover:underline {{ $wlan['enabled'] === true ? 'text-red-600' : 'text-green-600' }}">
                                            {{ $wlan['enabled'] === true ? __('Nonaktifkan') : __('Aktifkan') }}
                                        </button>
                                    </td>
                                @endif
                            </tr>
                            @if ($canManage)
                                <tr x-show="open" x-cloak>
                                    <td colspan="4" class="px-3 py-3 bg-gray-50">
                                        <div class="max-w-sm space-y-2">
                                            <p class="text-xs text-gray-500">{{ __('Isi salah satu atau keduanya. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil).') }}</p>
                                            <div>
                                                <label class="block text-xs font-medium mb-1">{{ __('SSID Baru') }}</label>
                                                <input type="text" id="cpe-wifi-ssid-{{ $device->id }}-{{ $wlan['index'] }}" maxlength="32" class="w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium mb-1">{{ __('Password Baru') }}</label>
                                                <input type="password" id="cpe-wifi-password-{{ $device->id }}-{{ $wlan['index'] }}" maxlength="63" class="w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm">
                                                <p class="text-xs text-gray-400 mt-1">{{ __('8-63 karakter (standar WPA-PSK).') }}</p>
                                            </div>
                                            <div class="text-xs text-red-600" id="cpe-wifi-error-{{ $device->id }}-{{ $wlan['index'] }}"></div>
                                            <button type="button" onclick="cpeSubmitWifi({{ $device->id }}, {{ (int) $wlan['index'] }})" class="px-3 py-1.5 bg-primary text-white rounded-md hover:opacity-90 text-xs">{{ __('Kirim Perintah') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
            <p class="text-sm text-gray-500">-</p>
        @endif
    </div>

    {{-- Ethernet ports — hanya ditampilkan kalau device ini punya data
         LANEthernetInterfaceConfig; belum pernah di-discover secara luas
         di fleet ini, jadi section ini absen untuk sebagian besar device. --}}
    @if (count($ethernetPorts) > 0)
        <div class="bg-white border border-gray-200 rounded-md p-5 mt-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('Ethernet Ports') }}</h2>
            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-1.5 text-left">{{ __('Port') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('Status') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('MAC') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('Enable') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($ethernetPorts as $port)
                            <tr>
                                <td class="px-3 py-1.5">{{ $port['name'] }}</td>
                                <td class="px-3 py-1.5">{{ $port['status'] ?? '-' }}</td>
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $port['mac_address'] ?? '-' }}</td>
                                <td class="px-3 py-1.5">{{ $port['enabled'] === true ? __('Ya') : ($port['enabled'] === false ? __('Tidak') : '-') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Traffic/Signal history charts, Speed profiles — sengaja tidak ada.
         Tidak ada data historis time-series tersimpan sekarang (future
         enhancement kalau nanti mau simpan histori RX/TX/traffic); Speed
         profiles adalah data billing/paket, bukan TR-069/GenieACS. --}}

    <div class="bg-white border border-gray-200 rounded-md p-5 mt-6 space-y-6">
        @include('cpe-devices._actions-and-history', ['device' => $device, 'canManage' => $canManage, 'historyLogs' => $historyLogs, 'connectedHosts' => $connectedHosts])
    </div>
</div>

@push('scripts')
    <script>
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            function cpeFlash(message, isError) {
                const el = document.getElementById('cpe-flash');
                el.textContent = message;
                el.classList.remove('hidden', 'text-green-600', 'text-red-600');
                el.classList.add(isError ? 'text-red-600' : 'text-green-600');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            async function cpeFetch(url, options) {
                options = options || {};
                options.headers = Object.assign({
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                }, options.headers || {});
                const response = await fetch(url, options);
                const body = await response.json().catch(() => ({}));
                return { ok: response.ok, status: response.status, body: body };
            }

            window.cpeReboot = function (id) {
                if (!confirm('Yakin reboot perangkat ini? Pelanggan akan terputus sebentar sampai perangkat menyala kembali. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil).')) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/reboot`, { method: 'POST' }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                });
            };

            // "Sync Sekarang" (2026-08-19) — no confirm() needed, this is a
            // read-refresh nudge, not a destructive/disruptive action like
            // Reboot. Doesn't auto-reload the page — the sync itself is not
            // instant (see CpeActionService::syncNow()'s own docblock), so
            // an immediate reload would just show the same stale data.
            window.cpeSyncNow = function (id) {
                cpeFetch(`/api/internal/cpe-devices/${id}/sync-now`, { method: 'POST' }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                });
            };

            // Per-SSID (2026-08-17) — element ids are now suffixed with the
            // SSID index (`cpe-wifi-ssid-{deviceId}-{ssidIndex}`) since the
            // WiFi/SSID table can render one edit form per row; `ssid_index`
            // is sent so CpeActionService::setWifiCredentials() targets the
            // right WLANConfiguration instance instead of always index 1.
            window.cpeSubmitWifi = function (id, ssidIndex) {
                const ssid = document.getElementById(`cpe-wifi-ssid-${id}-${ssidIndex}`).value;
                const password = document.getElementById(`cpe-wifi-password-${id}-${ssidIndex}`).value;
                const errorEl = document.getElementById(`cpe-wifi-error-${id}-${ssidIndex}`);
                errorEl.textContent = '';
                if (!ssid && !password) {
                    errorEl.textContent = 'Isi SSID atau password.';
                    return;
                }
                if (!confirm(`Yakin ganti SSID/password WiFi (index ${ssidIndex})? Semua perangkat pelanggan yang sudah terhubung ke SSID ini mungkin perlu connect ulang setelah perubahan diterapkan.`)) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/wifi`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ssid: ssid || null, password: password || null, ssid_index: ssidIndex }),
                }).then(({ ok, body }) => {
                    if (!ok && body.errors) {
                        errorEl.textContent = Object.values(body.errors).flat().join(' ');
                        return;
                    }
                    cpeFlash(body.message, !ok);
                });
            };

            // Aktif/Nonaktif toggle per SSID (2026-08-17) — the confirm()
            // message is deliberately stronger when DISABLING a currently
            // active SSID (real risk: cutting off a customer's WiFi they're
            // using right now), matching this task's explicit instruction
            // not to fire the command silently in that case.
            window.cpeToggleSsid = function (id, ssidIndex, currentlyEnabled) {
                let message = currentlyEnabled
                    ? `PERINGATAN: SSID index ${ssidIndex} sedang AKTIF. Menonaktifkannya akan memutus koneksi WiFi pelanggan yang sedang memakainya sampai diaktifkan lagi. Lanjutkan nonaktifkan?`
                    : `Aktifkan SSID index ${ssidIndex}?`;
                if (!confirm(message)) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/ssid-enabled`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ssid_index: ssidIndex, enabled: !currentlyEnabled }),
                }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                });
            };

            window.cpeRemove = function (id, customerName) {
                if (!confirm(`Yakin unbind device ini dari ${customerName}? Pasangan ini tidak akan di-match otomatis lagi.`)) return;
                cpeFetch(`/api/internal/cpe-devices/${id}`, { method: 'DELETE' }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                    if (ok) window.location.href = '{{ route('web.cpe-devices.index') }}';
                });
            };

            window.cpeReplaceModem = function (id, oldSerial) {
                const serial = document.getElementById(`cpe-replacement-serial-${id}`).value;
                const errorEl = document.getElementById(`cpe-replace-error-${id}`);
                errorEl.textContent = '';
                if (!serial) {
                    errorEl.textContent = 'Serial number baru wajib diisi.';
                    return;
                }
                if (!confirm(`Yakin ganti modem? Device lama (${oldSerial}) akan dilepas dari pelanggan ini.`)) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/replace-modem`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ serial_number: serial }),
                }).then(({ ok, body }) => {
                    if (!ok && body.errors) {
                        errorEl.textContent = Object.values(body.errors).flat().join(' ');
                        return;
                    }
                    cpeFlash(body.message, !ok);
                    if (ok) {
                        window.location.href = `/cpe-devices/${body.data.id}`;
                    }
                });
            };

            window.cpeToggleInactiveHosts = function (id) {
                const showInactive = document.getElementById(`cpe-hosts-show-inactive-${id}`).checked;
                const table = document.getElementById(`cpe-hosts-table-${id}`);
                if (!table) return;
                table.querySelectorAll('tbody tr[data-active]').forEach((tr) => {
                    const isActive = tr.getAttribute('data-active') === '1';
                    tr.style.display = (isActive || showInactive) ? '' : 'none';
                });
            };

            // PPPoE password reveal — never embedded in the page's initial
            // HTML (see CpeDeviceDetailController::pppoePassword()'s own
            // docblock). A fresh fetch happens only on click; the button is
            // disabled afterward rather than re-fetching on every click.
            window.cpeRevealPppoePassword = function (id) {
                const btn = document.getElementById(`cpe-pppoe-reveal-btn-${id}`);
                const masked = document.getElementById(`cpe-pppoe-password-masked-${id}`);
                const valueEl = document.getElementById(`cpe-pppoe-password-value-${id}`);
                btn.disabled = true;
                cpeFetch(`/api/internal/cpe-devices/${id}/pppoe-password`, { method: 'GET' }).then(({ ok, body }) => {
                    btn.disabled = false;
                    if (!ok) {
                        cpeFlash('Gagal mengambil password PPPoE.', true);
                        return;
                    }
                    masked.classList.add('hidden');
                    valueEl.classList.remove('hidden');
                    valueEl.textContent = body.password ?? '(kosong — perangkat tidak mengembalikan password PPPoE ini)';
                    btn.remove();
                });
            };
        })();
    </script>
@endpush
