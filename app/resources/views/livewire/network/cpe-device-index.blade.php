@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/css/jquery.dataTables.min.css">
@endpush

<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Perangkat CPE') }}</h1>

        <div class="flex items-center gap-2 text-sm">
            <label for="pollInterval" class="text-gray-500">{{ __('Auto-reload') }}</label>
            <select id="pollInterval" class="rounded-md border-gray-300 shadow-sm text-sm">
                @foreach ($this->pollIntervalOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div id="cpe-flash" class="mb-4 text-sm hidden"></div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table id="cpe-devices-table" class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th></th>
                    <th>{{ __('Pelanggan') }}</th>
                    <th>{{ __('Manufacturer / Model') }}</th>
                    <th>{{ __('Serial Number') }}</th>
                    <th>{{ __('MAC Address') }}</th>
                    <th>{{ __('RX Power') }}</th>
                    <th>{{ __('Online Duration') }}</th>
                    <th>{{ __('Uptime Modem') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/js/jquery.dataTables.min.js"></script>
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

            const table = $('#cpe-devices-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route('web.cpe-devices.internal.datatable') }}',
                lengthMenu: [10, 15, 25, 50],
                pageLength: 15,
                order: [],
                columns: [
                    {
                        data: null, orderable: false, className: 'cpe-expand-cell text-center cursor-pointer',
                        defaultContent: '<span class="text-primary">+</span>',
                    },
                    { data: 'customer_name', name: 'customer_name' },
                    {
                        data: null, orderable: true, name: 'manufacturer',
                        render: (data, type, row) => [row.manufacturer, row.model_name].filter(Boolean).join(' ') || '—',
                    },
                    { data: 'serial_number', name: 'serial_number' },
                    { data: 'mac_address', orderable: false, render: (d) => d ?? '-' },
                    {
                        data: 'rx_power_dbm', orderable: false,
                        render: (d) => d !== null ? Number(d).toFixed(2) + ' dBm' : '-',
                    },
                    { data: 'online_duration_text', name: 'online_duration_text' },
                    {
                        data: 'device_uptime_seconds', orderable: false,
                        render: function (seconds) {
                            if (seconds === null) return '-';
                            const totalMinutes = Math.floor(seconds / 60);
                            const days = Math.floor(totalMinutes / 1440);
                            const hours = Math.floor((totalMinutes % 1440) / 60);
                            const minutes = totalMinutes % 60;
                            return days > 0 ? `${days}h ${hours}j` : `${hours}j ${minutes}m`;
                        },
                    },
                    {
                        data: 'status_value', name: 'status_value',
                        render: function (value, type, row) {
                            const colors = { online: 'bg-green-100 text-green-700', offline: 'bg-red-100 text-red-700' };
                            const color = colors[value] || 'bg-yellow-100 text-yellow-700';
                            return `<span class="px-2 py-0.5 rounded-full text-xs ${color}">${row.status_label}</span>`;
                        },
                    },
                ],
            });

            $('#cpe-devices-table tbody').on('click', 'td.cpe-expand-cell', function () {
                const tr = $(this).closest('tr');
                const row = table.row(tr);
                const rowData = row.data();

                if (row.child.isShown()) {
                    row.child.hide();
                    $(this).find('span').text('+');
                    return;
                }

                $(this).find('span').text('…');
                row.child('<div class="p-4 text-sm text-gray-400">{{ __('Memuat...') }}</div>').show();

                fetch(`/api/internal/cpe-devices/${rowData.id}/detail`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((r) => r.text())
                    .then((html) => {
                        row.child(html).show();
                        $(this).find('span').text('−');
                    });
            });

            window.cpeReboot = function (id) {
                if (!confirm('Yakin reboot perangkat ini? Pelanggan akan terputus sebentar sampai perangkat menyala kembali. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil).')) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/reboot`, { method: 'POST' }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                });
            };

            window.cpeSubmitWifi = function (id) {
                const ssid = document.getElementById(`cpe-wifi-ssid-${id}`).value;
                const password = document.getElementById(`cpe-wifi-password-${id}`).value;
                const errorEl = document.getElementById(`cpe-wifi-error-${id}`);
                errorEl.textContent = '';
                if (!ssid && !password) {
                    errorEl.textContent = 'Isi SSID atau password.';
                    return;
                }
                if (!confirm('Yakin ganti SSID/password WiFi? Semua perangkat pelanggan yang sudah terhubung mungkin perlu connect ulang setelah perubahan diterapkan.')) return;
                cpeFetch(`/api/internal/cpe-devices/${id}/wifi`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ssid: ssid || null, password: password || null }),
                }).then(({ ok, body }) => {
                    if (!ok && body.errors) {
                        errorEl.textContent = Object.values(body.errors).flat().join(' ');
                        return;
                    }
                    cpeFlash(body.message, !ok);
                });
            };

            window.cpeRemove = function (id, customerName) {
                if (!confirm(`Yakin unbind device ini dari ${customerName}? Pasangan ini tidak akan di-match otomatis lagi.`)) return;
                cpeFetch(`/api/internal/cpe-devices/${id}`, { method: 'DELETE' }).then(({ ok, body }) => {
                    cpeFlash(body.message, !ok);
                    if (ok) table.ajax.reload(null, false);
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
                    if (ok) table.ajax.reload(null, false);
                });
            };

            // Auto-reload — plain setInterval calling DataTables' own
            // ajax.reload(), never a full page reload. Off by default so a
            // page nobody is actively watching doesn't keep hitting the
            // server for no reason.
            let pollTimer = null;
            document.getElementById('pollInterval').addEventListener('change', function (e) {
                if (pollTimer) clearInterval(pollTimer);
                const seconds = parseInt(e.target.value, 10);
                if (!Number.isNaN(seconds) && seconds > 0) {
                    pollTimer = setInterval(() => table.ajax.reload(null, false), seconds * 1000);
                }
            });
        })();
    </script>
@endpush
