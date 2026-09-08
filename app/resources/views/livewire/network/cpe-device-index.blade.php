@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/css/jquery.dataTables.min.css">
    @include('components.datatable-styles')
    <style>
        /* Page-specific column widths — kept here rather than in the
           reusable partial since these depend on this exact column set.
           Tuned so the 9 columns fit within Tailwind's lg: (1024px)
           breakpoint without scrollX (Bagian C) — verified empirically at
           1024px with the real w-64 sidebar, not just eyeballed. */
        #cpe-devices-table.dataTable {
            table-layout: fixed;
            width: 100% !important;
        }

        #cpe-devices-table th:nth-child(1),
        #cpe-devices-table td:nth-child(1) { width: 56px; }

        #cpe-devices-table th:nth-child(2),
        #cpe-devices-table td:nth-child(2) { width: 15%; }

        /* Status is fixed short text ("Online"/"Offline") — never needs
           real truncation room, so it gets just enough to render the badge
           comfortably and nothing more (freed up for the columns that
           actually need it). */
        #cpe-devices-table th:nth-child(3),
        #cpe-devices-table td:nth-child(3) { width: 7%; }

        #cpe-devices-table th:nth-child(4),
        #cpe-devices-table td:nth-child(4) { width: 14%; }

        #cpe-devices-table th:nth-child(5),
        #cpe-devices-table td:nth-child(5) { width: 15%; }

        /* MAC Address is "-" for most of this fleet today (documented
           vendor-tree limitation, see CLAUDE.md's GenieACS Connected
           Clients section) — narrower on purpose, the column stays for
           when it does resolve. */
        #cpe-devices-table th:nth-child(6),
        #cpe-devices-table td:nth-child(6) { width: 11%; }

        #cpe-devices-table th:nth-child(7),
        #cpe-devices-table td:nth-child(7) { width: 11%; }

        #cpe-devices-table th:nth-child(8),
        #cpe-devices-table td:nth-child(8) { width: 12%; }

        #cpe-devices-table th:nth-child(9),
        #cpe-devices-table td:nth-child(9) { width: 11%; }

        /* Headers wrap onto 2 lines rather than getting cut with an
           ellipsis — at 1024px the fixed % column widths above are tight
           enough that a forced single-line header would overlap its
           neighbor; a natural 2-line wrap reads better than "MANUFACT...".
           Data cells (below) still truncate to one line — a value like a
           serial number genuinely losing meaning if wrapped. */
        #cpe-devices-table th {
            white-space: normal;
            line-height: 1.2;
            vertical-align: bottom;
        }

        /* v0.17.0 Langkah 2 — pin the "Detail" column (leftmost) while the
           9-col table scrolls horizontally on a phone, so it never scrolls
           out of reach. Left, not right, because that's where the column
           already is — no reorder. bg on both th/td so neighbours don't
           bleed through under it. Only engages once there's real overflow. */
        #cpe-devices-table th:first-child,
        #cpe-devices-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
        }
        #cpe-devices-table td:first-child { background-color: #fff; }
        #cpe-devices-table th:first-child { background-color: #f9fafb; }

        /* Same treatment for the "Belum Ter-bind" table — pin the Serial
           Number column (the row identifier) so you keep your place while
           scrolling its 7 columns. */
        #unbound-genieacs-table th:first-child,
        #unbound-genieacs-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
        }
        #unbound-genieacs-table td:first-child { background-color: #fff; }
        #unbound-genieacs-table th:first-child { background-color: #f9fafb; }

        /* Single-line truncation for every column EXCEPT Pelanggan (column
           2), which stacks name + CID on two lines on purpose, and Status
           (column 3), whose badge must never get an ellipsis cut through
           it. */
        #cpe-devices-table td:not(:nth-child(2)):not(:nth-child(3)) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        #cpe-devices-table td:nth-child(2) {
            overflow: hidden;
        }
    </style>
@endpush

<div class="p-6 w-full">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
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

    {{-- overflow-x-auto only actually engages below lg: once the fixed
         column widths above stop fitting — see Bagian C. --}}
    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table id="cpe-devices-table" class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider">
                <tr>
                    <th></th>
                    <th>{{ __('Pelanggan') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Manufacturer / Model') }}</th>
                    <th>{{ __('Serial Number') }}</th>
                    <th>{{ __('MAC Address') }}</th>
                    <th>{{ __('RX Power') }}</th>
                    <th>{{ __('Online Duration') }}</th>
                    <th>{{ __('Uptime Modem') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    {{-- "Belum Ter-bind" — device yang ADA di GenieACS tapi belum punya
         baris cpe_devices sama sekali. Menunggu auto-matcher / reconcile /
         bind manual dari halaman pelanggan. Admin/NOC only
         (CpeDevicePolicy::viewUnbound); reseller tidak melihat section ini.
         Client-side DataTables (dataset kecil, dihitung dari diff GenieACS
         vs cpe_devices) + auto-reload sendiri, terpisah dari tabel utama. --}}
    @can('viewUnbound', \App\Models\CpeDevice::class)
        <div class="mt-10">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">{{ __('Device GenieACS Belum Ter-bind') }}</h2>
                    <p class="text-xs text-gray-500 mt-0.5 max-w-2xl">
                        {{ __('Device yang sudah Inform ke GenieACS tapi belum tertaut ke Perangkat CPE mana pun. Menunggu auto-matcher, reconcile, atau bind manual dari halaman pelanggan. "Serial dikenal" = BOSS App sudah punya baris CPE untuk serial ini (tinggal reconcile); "Belum dikenal" = perlu dibuatkan/ditautkan ke pelanggan.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2 text-sm shrink-0">
                    <label for="unboundPollInterval" class="text-gray-500">{{ __('Auto-reload') }}</label>
                    <select id="unboundPollInterval" class="rounded-md border-gray-300 shadow-sm text-sm">
                        @foreach ($this->pollIntervalOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <p id="unbound-genieacs-error"
               class="hidden mb-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2"></p>

            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table id="unbound-genieacs-table" class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th>{{ __('Serial Number') }}</th>
                            <th>{{ __('Manufacturer / Product Class') }}</th>
                            <th>{{ __('MAC Address') }}</th>
                            <th>{{ __('Pertama Terlihat') }}</th>
                            <th>{{ __('Terakhir Inform') }}</th>
                            <th>{{ __('ACS URL (Option 43)') }}</th>
                            <th>{{ __('Status di BOSS App') }}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    @endcan
</div>

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/2.3.7/js/jquery.dataTables.min.js"></script>
    <script>
        (function () {
            const table = $('#cpe-devices-table').DataTable({
                processing: true,
                serverSide: true,
                autoWidth: false,
                ajax: '{{ route('web.cpe-devices.internal.datatable') }}',
                lengthMenu: [10, 15, 25, 50],
                pageLength: 15,
                order: [],
                columns: [
                    {
                        data: 'id', orderable: false, className: 'text-center',
                        render: (id) => `<a href="/cpe-devices/${id}" class="text-primary hover:underline">{{ __('Detail') }}</a>`,
                    },
                    {
                        data: 'customer_name', name: 'customer_name',
                        render: function (data, type, row) {
                            if (type !== 'display') return data;
                            const name = data ?? '—';
                            const cid = row.customer_cid
                                ? `<div class="text-[11px] text-gray-400 font-mono truncate" title="${row.customer_cid}">${row.customer_cid}</div>`
                                : '';
                            return `<div class="truncate" title="${name}">${name}</div>${cid}`;
                        },
                    },
                    {
                        data: 'status_value', name: 'status_value',
                        render: function (value, type, row) {
                            if (type !== 'display') return value;
                            const colors = { online: 'bg-green-100 text-green-700', offline: 'bg-red-100 text-red-700' };
                            const color = colors[value] || 'bg-yellow-100 text-yellow-700';
                            return `<span class="px-2 py-0.5 rounded-full text-xs ${color}">${row.status_label}</span>`;
                        },
                    },
                    {
                        data: null, orderable: true, name: 'manufacturer',
                        render: (data, type, row) => {
                            const text = [row.manufacturer, row.model_name].filter(Boolean).join(' ') || '—';
                            return type === 'display' ? `<span title="${text}">${text}</span>` : text;
                        },
                    },
                    {
                        data: 'serial_number', name: 'serial_number',
                        render: (data, type) => type === 'display' ? `<span title="${data}">${data}</span>` : data,
                    },
                    {
                        data: 'mac_address', orderable: false,
                        render: (d, type) => {
                            const text = d ?? '-';
                            return type === 'display' ? `<span title="${text}">${text}</span>` : text;
                        },
                    },
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
                ],
            });

            // Row actions (Reboot/Ganti WiFi/Ganti Modem/Remove/Riwayat/Client
            // Terhubung) moved to the standalone /cpe-devices/{id} page
            // (2026-08-16) — the "Detail" column above is now a plain link,
            // not a DataTables child-row trigger. See cpe-devices/show.blade.php
            // for that page's own copy of these action handlers.

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

            // --- Section "Belum Ter-bind" (admin/NOC only — policy-gated in
            // the markup, so the table element is simply absent for reseller
            // users). Client-side DataTables: the endpoint returns the whole
            // (small) unbound set as {data:[...]}, computed from a GenieACS
            // vs cpe_devices diff — no server-side pagination to wire up.
            const unboundEl = document.getElementById('unbound-genieacs-table');
            if (unboundEl) {
                const errBox = document.getElementById('unbound-genieacs-error');
                const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => (
                    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
                ));
                const fmtTime = (iso) => {
                    if (!iso) return '<span class="text-gray-400">-</span>';
                    const d = new Date(iso);
                    if (Number.isNaN(d.getTime())) return '<span class="text-gray-400">-</span>';
                    return d.toLocaleString('id-ID', {
                        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
                    });
                };

                const unboundTable = $('#unbound-genieacs-table').DataTable({
                    processing: true,
                    serverSide: false,
                    autoWidth: false,
                    ajax: {
                        url: '{{ route('web.cpe-devices.internal.unbound-genieacs') }}',
                        dataSrc: function (json) {
                            if (json && json.error) {
                                errBox.textContent = json.error;
                                errBox.classList.remove('hidden');
                            } else {
                                errBox.classList.add('hidden');
                            }
                            return (json && json.data) || [];
                        },
                    },
                    lengthMenu: [10, 25, 50, 100],
                    pageLength: 25,
                    order: [[4, 'desc']],
                    language: {
                        emptyTable: '{{ __('Tidak ada device GenieACS yang belum ter-bind.') }}',
                        zeroRecords: '{{ __('Tidak ada yang cocok dengan pencarian.') }}',
                        processing: '{{ __('Memuat…') }}',
                    },
                    columns: [
                        {
                            data: 'serial_number',
                            render: (d, type) => {
                                if (type !== 'display') return d ?? '';
                                return d ? `<span class="font-mono" title="${esc(d)}">${esc(d)}</span>` : '-';
                            },
                        },
                        {
                            data: null, orderable: true,
                            render: (row, type) => {
                                const text = [row.manufacturer, row.product_class].filter(Boolean).join(' ') || '-';
                                return type === 'display' ? `<span title="${esc(text)}">${esc(text)}</span>` : text;
                            },
                        },
                        {
                            data: 'mac_address', orderable: false,
                            render: (d, type) => {
                                if (type !== 'display') return d ?? '';
                                return d
                                    ? `<span class="font-mono">${esc(d)}</span>`
                                    : '<span class="text-gray-400">-</span>';
                            },
                        },
                        { data: 'registered_at', render: (d, type) => type === 'display' ? fmtTime(d) : (d || '') },
                        { data: 'last_inform_at', render: (d, type) => type === 'display' ? fmtTime(d) : (d || '') },
                        {
                            data: 'acs_url', orderable: false,
                            render: function (url, type) {
                                if (type !== 'display') return url ?? '';
                                if (!url) {
                                    return '<span class="text-amber-600" title="ManagementServer.URL kosong — Option 43 tidak terbaca perangkat ini / perlu set manual">— kosong (manual)</span>';
                                }
                                return `<span class="font-mono text-green-700 inline-block max-w-[220px] truncate align-bottom" title="${esc(url)}">${esc(url)}</span>`;
                            },
                        },
                        {
                            data: 'boss_state',
                            render: function (state, type, row) {
                                if (type !== 'display') return state ?? '';
                                if (state === 'serial_known') {
                                    const who = row.boss_customer ? ` · ${esc(row.boss_customer)}` : '';
                                    return `<span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700" title="BOSS App sudah punya baris CPE untuk serial ini — tinggal reconcile / auto-match">serial dikenal${who}</span>`;
                                }
                                return '<span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700" title="Belum ada baris CPE untuk serial ini — perlu dibuatkan / ditautkan ke pelanggan">belum dikenal</span>';
                            },
                        },
                    ],
                });

                let unboundTimer = null;
                document.getElementById('unboundPollInterval').addEventListener('change', function (e) {
                    if (unboundTimer) clearInterval(unboundTimer);
                    const seconds = parseInt(e.target.value, 10);
                    if (!Number.isNaN(seconds) && seconds > 0) {
                        unboundTimer = setInterval(() => unboundTable.ajax.reload(null, false), seconds * 1000);
                    }
                });
            }
        })();
    </script>
@endpush
