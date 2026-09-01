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
        <div class="flex items-center gap-3">
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
        <div class="flex items-center justify-between gap-2">
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
                <span class="inline-flex gap-2">
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

    <p class="text-xs text-gray-400">
        {{ __('Ganti lapisan Peta / Satelit dan nyalakan / matikan kategori lewat kontrol di pojok kanan atas peta. Klik satu garis kabel untuk mengeditnya: klik di sepanjang garis untuk menambah titik belok, seret titik yang ada untuk memindah, klik dua kali titik untuk menghapus, lalu "Simpan Rute" (menimpa semua waypoint kabel itu).') }}
    </p>
</div>
