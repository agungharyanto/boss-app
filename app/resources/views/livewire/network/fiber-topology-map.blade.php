{{--
    v0.16.0 Langkah 8/9/10 — "Peta Topologi". Leaflet (OSM + Esri
    satellite base layers) bundled via Vite. Layer control (top-right):
    checklist toggles per category — Kabel / OTB / Closure / ODC / ODP /
    Pelanggan. Default ON: the four fiber node types; default OFF: cable
    lines + customers (same "don't render everything on open" principle as
    the no-default-lines rule). Cable lines appear per selected CABLE
    (unit of selection is always the cable, never a core). "Ekspor KMZ"
    shows the same checklist before downloading.
--}}
@php
    $catLabels = ['cable' => __('Kabel'), 'otb' => 'OTB', 'closure' => 'Closure', 'odc' => 'ODC', 'odp' => 'ODP', 'customer' => __('Pelanggan')];
@endphp
<div class="p-6 max-w-6xl mx-auto space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Peta Topologi') }}</h1>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <button
                type="button"
                wire:click="$toggle('showExportPanel')"
                class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
            >{{ $showExportPanel ? __('Tutup') : __('Ekspor KMZ') }}</button>
            <a href="{{ route('web.fiber-nodes.index') }}" class="text-sm text-primary hover:underline">{{ __('Daftar Perangkat Passive') }} &rarr;</a>
        </div>
    </div>

    <p class="text-sm text-gray-500">
        {{ __('Marker menandai setiap OTB / Closure / ODC / ODP / Pelanggan yang punya koordinat. Nyalakan / matikan tiap kategori lewat kontrol lapisan di pojok kanan atas peta. Garis kabel hanya muncul setelah kamu memilih satu kabel — dari halaman ini atau lewat tombol "Lihat di peta" di detail perangkat. Warna garis netral (satu kabel berisi banyak core dengan warna berbeda).') }}
    </p>

    @if (session('map-status'))
        <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('map-status') }}</div>
    @endif

    {{-- Ekspor KMZ checklist --}}
    @if ($showExportPanel)
        <div class="border border-gray-200 rounded-md p-3 space-y-3 bg-gray-50">
            <p class="text-xs font-medium text-gray-700">{{ __('Pilih kategori yang diekspor ke KMZ:') }}</p>
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                @foreach ($categories as $cat)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="exportCategories" value="{{ $cat }}" class="rounded border-gray-300 text-primary focus:ring-primary">
                        <span>{{ $catLabels[$cat] }}</span>
                    </label>
                @endforeach
            </div>
            <button
                type="button"
                wire:click="exportKmz"
                wire:loading.attr="disabled"
                wire:target="exportKmz"
                @disabled(count($exportCategories) === 0)
                class="px-3 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50"
            >{{ __('Download KMZ') }}</button>
            @if (count($exportCategories) === 0)
                <span class="text-xs text-gray-400">{{ __('Centang minimal satu kategori.') }}</span>
            @endif
        </div>
    @endif

    {{-- Cable multi-select checklist --}}
    <div class="border border-gray-200 rounded-md p-3 space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-medium text-gray-700">{{ __('Tampilkan kabel di peta') }} ({{ count($selectedCableIds) }})</span>
            @if (count($cableOptions) > 0)
                <button type="button" wire:click="toggleAllCables" class="text-xs text-primary hover:underline">
                    {{ $allCablesSelected ? __('Kosongkan') : __('Pilih Semua') }}
                </button>
            @endif
        </div>
        @if (count($cableOptions) === 0)
            <p class="text-xs text-gray-400 italic">{{ __('Belum ada kabel dengan koordinat lengkap di kedua ujung.') }}</p>
        @else
            {{-- Fixed height (~3 rows) — the list scrolls inside here
                 instead of pushing the map down. "Pilih Semua"/"Kosongkan"
                 stays above, outside the scroll area. --}}
            <div class="max-h-24 overflow-y-auto space-y-1 pr-1 border-t border-gray-100 pt-1">
                @foreach ($cableOptions as $opt)
                    <label class="flex items-center gap-2 text-sm px-1.5 py-1 rounded hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" wire:model.live="selectedCableIds" value="{{ $opt['cable_id'] }}" class="rounded border-gray-300 text-primary focus:ring-primary shrink-0">
                        <span>{{ $opt['label'] }}</span>
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Currently-drawn cables (active summary; uncheck via the checklist above or the × here) --}}
    @if (count($lines) > 0)
        <ul class="flex flex-wrap gap-2" aria-label="{{ __('Kabel yang sedang ditampilkan') }}">
            @foreach ($lines as $line)
                <li class="inline-flex items-center gap-2 border border-gray-300 rounded-full pl-2 pr-1 py-1 text-xs bg-white">
                    <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $line['color'] }};" role="img" aria-label="{{ __('Warna garis kabel') }}"></span>
                    <span class="text-gray-700">{{ $line['label'] }}</span>
                    <button
                        type="button"
                        wire:click="hideCable({{ $line['cable_id'] }})"
                        class="w-6 h-6 inline-flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500"
                        aria-label="{{ __('Sembunyikan dari peta') }}"
                    >&times;</button>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-400">{{ __('Semua garis kabel warna netral yang sama walau banyak dipilih sekaligus. Matikan lapisan "Kabel" di kontrol peta untuk menyembunyikan semuanya sementara.') }}</p>
    @else
        <p class="text-sm text-gray-400 italic">{{ __('Belum ada kabel dipilih.') }}</p>
    @endif

    {{-- Map (Leaflet owns this subtree) --}}
    <div
        wire:ignore
        x-data="fiberTopologyMap({
            markers: @js($markers),
            customers: @js($customers),
            lines: @js($lines),
            canManage: @js($canManage),
            defaultLayers: @js($defaultLayers),
        })"
        class="rounded-md border border-gray-200 overflow-hidden"
    >
        <div x-ref="map" class="h-[32rem] w-full bg-gray-100" role="application" aria-label="{{ __('Peta topologi fiber') }}"></div>

        <div x-show="editCableId !== null" x-cloak class="flex flex-wrap items-center gap-2 p-2 bg-gray-50 border-t border-gray-200 text-xs">
            <span class="text-gray-600">{{ __('Mengedit rute') }}: <span class="font-medium" x-text="editLabel"></span></span>
            <span class="text-gray-400" x-text="editWaypoints.length + ' {{ __('titik belok') }}'"></span>
            <template x-if="canManage">
                <span class="inline-flex flex-wrap gap-2">
                    <button type="button" x-on:click="toggleTapAdd()" x-bind:class="tapAddMode ? 'bg-primary text-white border-primary' : 'border-gray-300 hover:bg-gray-50'" class="px-3 py-1 border rounded-md">
                        <span x-show="!tapAddMode">{{ __('Mode Edit Rute (tap peta)') }}</span>
                        <span x-show="tapAddMode">{{ __('Mode Edit AKTIF — tap peta untuk tambah titik') }}</span>
                    </button>
                    <button type="button" x-on:click="persistRoute()" class="px-3 py-1 bg-primary text-white rounded-md hover:opacity-90">{{ __('Simpan Rute') }}</button>
                    <button type="button" x-on:click="resetRoute()" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batalkan perubahan') }}</button>
                    <button type="button" x-on:click="stopEditing()" class="px-3 py-1 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Selesai') }}</button>
                </span>
            </template>
            <template x-if="!canManage">
                <span class="text-gray-400">{{ __('Hanya bisa dilihat.') }}</span>
            </template>
        </div>
    </div>

    {{-- v0.16.1 Bagian E — daftar waypoint di bawah peta: reorder & hapus
         per baris tanpa perlu drag presisi di layar kecil. Di dalam
         wire:ignore x-data yang sama dengan peta (Alpine yang kelola). --}}
    <div x-show="editCableId !== null && canManage" x-cloak class="border border-gray-200 rounded-md">
        <div class="px-3 py-2 bg-gray-50 border-b border-gray-100 text-xs font-medium text-gray-600 flex items-center justify-between">
            <span>{{ __('Titik Belok Rute') }} (<span x-text="editWaypoints.length"></span>)</span>
            <span class="text-gray-400 font-normal">{{ __('urutan = arah rute') }}</span>
        </div>
        <template x-if="editWaypoints.length === 0">
            <p class="px-3 py-3 text-xs text-gray-400 italic">{{ __('Belum ada titik belok. Nyalakan "Mode Edit Rute" lalu tap di peta, atau seret garis di peta.') }}</p>
        </template>
        <ul class="divide-y divide-gray-100">
            <template x-for="(wp, idx) in editWaypoints" :key="idx">
                <li class="px-3 py-2 flex items-center gap-2 text-xs">
                    <span class="w-6 text-gray-400" x-text="'#' + (idx + 1)"></span>
                    <span class="flex-1 font-mono text-gray-600" x-text="fmtLatLng(wp)"></span>
                    <button type="button" x-on:click="moveWaypoint(idx, -1)" x-bind:disabled="idx === 0" class="w-7 h-7 inline-flex items-center justify-center border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed" aria-label="{{ __('Naikkan urutan') }}">&uarr;</button>
                    <button type="button" x-on:click="moveWaypoint(idx, 1)" x-bind:disabled="idx === editWaypoints.length - 1" class="w-7 h-7 inline-flex items-center justify-center border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed" aria-label="{{ __('Turunkan urutan') }}">&darr;</button>
                    <button type="button" x-on:click="removeWaypointAt(idx)" class="w-7 h-7 inline-flex items-center justify-center border border-red-200 text-red-600 rounded-md hover:bg-red-50" aria-label="{{ __('Hapus titik ini') }}">&times;</button>
                </li>
            </template>
        </ul>
    </div>

    <p class="text-xs text-gray-400">
        {{ __('Ganti lapisan Peta / Satelit dan nyalakan / matikan kategori lewat kontrol di pojok kanan atas peta. Klik satu garis kabel untuk mengeditnya. Desktop: seret titik di peta untuk memindah, klik garis untuk sisip titik, klik dua kali titik untuk hapus. HP: nyalakan "Mode Edit Rute" lalu tap di peta untuk menambah titik di ujung rute, dan pakai daftar di bawah peta untuk urut / hapus. Lalu "Simpan Rute" (menimpa semua waypoint kabel itu).') }}
    </p>

    {{--
        v0.16.1 Bagian F — panel info ringkas saat marker OTB/Closure/ODC/
        ODP di-tap. DI LUAR wire:ignore (Livewire yang render), bottom sheet
        di HP / side panel di desktop, memakai konvensi fixed + backdrop +
        z-index dari v0.17.0 Langkah 2.2 (backdrop z-[1100], panel z-[1200])
        — BUKAN komponen drawer sidebar. Bukan seluruh tabel Koneksi Core:
        1 foto, ringkasan core terpakai/cadangan, badge kapasitas, dan link
        "Lihat Detail Lengkap" ke FiberNodeDetail.
    --}}
    @if ($markerPanel)
        <div
            class="fixed inset-0 z-[1100] bg-black/40"
            wire:click="closeMarkerPanel"
            aria-hidden="true"
        ></div>
        <div
            class="fixed z-[1200] bg-white shadow-2xl overflow-y-auto flex flex-col
                   inset-x-0 bottom-0 max-h-[80vh] rounded-t-2xl
                   md:inset-y-0 md:right-0 md:left-auto md:w-96 md:max-h-none md:rounded-none"
            role="dialog" aria-modal="true"
            aria-label="{{ __('Info titik topologi') }}"
        >
            <div class="flex items-start justify-between gap-3 p-4 border-b border-gray-100">
                <div>
                    <p class="text-base font-semibold text-gray-800">{{ $markerPanel['title'] }}</p>
                    <p class="text-xs text-gray-500">{{ $markerPanel['subtitle'] }}</p>
                </div>
                <button
                    type="button"
                    wire:click="closeMarkerPanel"
                    class="w-8 h-8 shrink-0 inline-flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500 text-lg"
                    aria-label="{{ __('Tutup panel') }}"
                >&times;</button>
            </div>

            <div class="p-4 space-y-4">
                {{-- Foto utama --}}
                @if ($markerPanel['photo_url'])
                    <figure class="space-y-1">
                        <img
                            src="{{ $markerPanel['photo_url'] }}"
                            alt="{{ $markerPanel['photo_caption'] ?: __('Foto :titik', ['titik' => $markerPanel['title']]) }}"
                            class="w-full rounded-md border border-gray-200 object-cover max-h-56"
                            loading="lazy"
                        >
                        @if ($markerPanel['photo_caption'])
                            <figcaption class="text-xs text-gray-500">{{ $markerPanel['photo_caption'] }}</figcaption>
                        @endif
                    </figure>
                @else
                    <div class="w-full rounded-md border border-dashed border-gray-200 bg-gray-50 py-8 text-center text-xs text-gray-400">
                        {{ __('Belum ada foto') }}
                    </div>
                @endif

                {{-- Ringkasan core (bukan tabel penuh) --}}
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-md border border-gray-200 p-2">
                        <p class="text-lg font-semibold text-gray-800">{{ $markerPanel['cores']['used'] }}</p>
                        <p class="text-[11px] text-gray-500">{{ __('Core terpakai') }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 p-2">
                        <p class="text-lg font-semibold text-gray-800">{{ $markerPanel['cores']['spare'] }}</p>
                        <p class="text-[11px] text-gray-500">{{ __('Core cadangan') }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 p-2">
                        <p class="text-lg font-semibold text-gray-800">{{ $markerPanel['cores']['total'] }}</p>
                        <p class="text-[11px] text-gray-500">{{ __('Total core') }}</p>
                    </div>
                </div>

                {{-- Badge kapasitas — warna BUKAN satu-satunya sinyal:
                     ada titik warna + label kata + angka persen/rasio. --}}
                @php($cap = $markerPanel['capacity'])
                <div class="flex items-center gap-2 rounded-md border border-gray-200 p-3">
                    <span
                        class="w-3 h-3 rounded-full shrink-0 border border-black/10"
                        style="background-color: {{ $cap['color'] }};"
                        aria-hidden="true"
                    ></span>
                    <span class="text-sm font-medium text-gray-700 capitalize">{{ $cap['label'] }}</span>
                    <span class="ml-auto text-xs text-gray-500">
                        @if ($markerPanel['kind'] === 'odp')
                            {{ $cap['used'] }}/{{ $cap['total'] }} {{ __('port') }}
                        @else
                            {{ $cap['used'] }}/{{ $cap['total'] }} {{ __('core') }}
                        @endif
                        @if (! is_null($cap['percent']))
                            &middot; {{ $cap['percent'] }}%
                        @endif
                    </span>
                </div>
            </div>

            <div class="mt-auto p-4 border-t border-gray-100">
                <a
                    href="{{ $markerPanel['detail_url'] }}"
                    class="block w-full text-center px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90"
                >{{ __('Lihat Detail Lengkap') }} &rarr;</a>
            </div>
        </div>
    @endif
</div>
