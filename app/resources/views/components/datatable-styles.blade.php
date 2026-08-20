{{--
    Reusable Tailwind-consistent restyle for vanilla jquery.dataTables (the
    CDN build, same convention as every other external asset in this app —
    see CLAUDE.md's Frontend build section). DataTables generates its own
    length-menu/search-box/pagination/info markup at runtime via JS, so
    Tailwind utility classes can't be applied to it directly at write time —
    this CSS block targets DataTables' own stable default class names
    instead, restyled to match the app's existing form-control look
    (`rounded-md border-gray-300 shadow-sm text-sm`, see e.g. the search
    input in customers/customer-index.blade.php) and the `--color-primary`
    CSS variable (resources/views/layouts/app.blade.php) so pagination's
    active state follows each user's own theme color.

    HOW TO REUSE ON ANOTHER PAGE (built for /cpe-devices first, v0.7.6
    follow-up — intentionally not yet applied anywhere else):
      1. @include this partial inside that page's own @push('styles').
      2. Load jQuery + DataTables from the same cdnjs URLs as
         cpe-device-index.blade.php inside @push('scripts').
      3. Initialize $('#your-table').DataTable({...}) with page-specific
         columns/ajax — this partial only handles the surrounding chrome,
         never table columns/data, which stay page-specific on purpose.
--}}
<style>
    .dataTables_wrapper {
        margin-top: 0.25rem;
    }

    /* Space between the length/search controls row and the table itself —
       the thing this task's Bagian B specifically asked for, since
       DataTables' own default has these sitting almost flush against the
       table border. */
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        margin-bottom: 1rem;
    }

    .dataTables_wrapper .dataTables_length label,
    .dataTables_wrapper .dataTables_filter label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: #6b7280;
        font-weight: normal;
    }

    .dataTables_wrapper .dataTables_length select,
    .dataTables_wrapper .dataTables_filter input {
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        font-size: 0.875rem;
        padding: 0.375rem 0.625rem;
    }

    .dataTables_wrapper .dataTables_length select:focus,
    .dataTables_wrapper .dataTables_filter input:focus {
        outline: 2px solid transparent;
        outline-offset: 2px;
        border-color: var(--color-primary, #2563eb);
        box-shadow: 0 0 0 1px var(--color-primary, #2563eb);
    }

    .dataTables_wrapper .dataTables_filter input {
        margin-left: 0.5rem;
    }

    /* Info text + pagination row, below the table — same "give it room"
       treatment as the controls above. */
    .dataTables_wrapper .dataTables_info {
        margin-top: 1rem;
        font-size: 0.8125rem;
        color: #6b7280;
    }

    .dataTables_wrapper .dataTables_paginate {
        margin-top: 1rem;
        display: flex;
        gap: 0.25rem;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0.25rem 0.625rem;
        border-radius: 0.375rem;
        border: 1px solid transparent;
        font-size: 0.8125rem;
        color: #374151;
        cursor: pointer;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        color: #d1d5db;
        cursor: default;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button:not(.disabled):not(.current):hover {
        background-color: #f9fafb;
        border-color: #e5e7eb;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background-color: var(--color-primary, #2563eb);
        border-color: var(--color-primary, #2563eb);
        color: #fff;
    }

    .dataTables_wrapper .dataTables_processing {
        font-size: 0.875rem;
        color: #6b7280;
    }

    /* Compact cell padding — replaces jquery.dataTables.min.css's own
       bulkier default so more columns fit per viewport width without
       needing scrollX (see Bagian C — the goal is no horizontal scroll at
       Tailwind's lg: breakpoint and up). */
    table.dataTable thead th,
    table.dataTable tbody td {
        padding: 0.5rem 0.75rem;
    }
</style>
