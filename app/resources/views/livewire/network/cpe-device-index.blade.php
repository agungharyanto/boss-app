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
        })();
    </script>
@endpush
