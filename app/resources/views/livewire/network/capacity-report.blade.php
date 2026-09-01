{{--
    v0.16.0 Langkah 4 — data-dense layout (ui-ux-pro-max: compact
    padding, sortable-feeling tables, KPI-style rows) — one table per
    category rather than one giant mixed table, since "used/total" means
    a different physical thing per category (ODP port vs splitter leg vs
    fiber core).
--}}
<div class="p-6 max-w-6xl mx-auto space-y-6">
    <h1 class="text-2xl font-semibold text-gray-800">{{ __('Kapasitas Jaringan') }}</h1>

    <div class="flex flex-wrap items-center gap-3">
        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Cari kode/nama...') }}"
            class="rounded-md border-gray-300 shadow-sm text-sm flex-1 min-w-[200px]"
        >
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model.live="onlyNearFull" class="rounded border-gray-300">
            {{ __('Hanya tampilkan >80% penuh') }}
        </label>
    </div>

    @foreach ([
        ['title' => __('ODP — Port Terpakai'), 'rows' => $odps],
        ['title' => __('Splitter — Output Leg Terpakai'), 'rows' => $splitters],
        ['title' => __('Kabel Fiber — Core Terpakai'), 'rows' => $cables],
    ] as $section)
        <div class="border border-gray-200 rounded-md overflow-x-auto">
            <h2 class="text-sm font-semibold text-gray-700 px-4 py-3 bg-gray-50 border-b border-gray-200">{{ $section['title'] }}</h2>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Nama') }}</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-500 text-xs w-64">{{ __('Kapasitas') }}</th>
                        <th class="px-4 py-2 text-right font-medium text-gray-500 text-xs">{{ __('Terpakai / Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($section['rows'] as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-700">{{ $row->label }}</td>
                            <td class="px-4 py-2">
                                @include('livewire.network.partials.capacity-progress-bar', ['percent' => $row->percent])
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $row->used }} / {{ $row->total ?? '?' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-400">{{ __('Tidak ada data.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
