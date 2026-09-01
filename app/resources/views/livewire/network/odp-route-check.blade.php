{{--
    v0.16.0 Langkah 11 — "Cek Jalur ke ODP". Prospect point (own GPS or
    manual pin) + nearest ODP candidates + real road route(s) from OSRM
    (shortest = "Rekomendasi", rest = "Alternatif B/C/…"; a straight-line
    estimate is shown clearly labelled when OSRM is unavailable). Each
    option can carry a free-text note and be saved to sales_route_notes —
    pure reference, no billing.
--}}
<div class="p-6 max-w-6xl mx-auto space-y-4"
     x-data="{ geoBusy: false, locate() {
        if (!navigator.geolocation) { alert('Perangkat tidak mendukung lokasi.'); return; }
        this.geoBusy = true;
        navigator.geolocation.getCurrentPosition(
            (p) => { $wire.set('latitude', p.coords.latitude.toFixed(7)); $wire.set('longitude', p.coords.longitude.toFixed(7)); this.geoBusy = false; },
            () => { this.geoBusy = false; alert('Gagal mengambil lokasi.'); },
            { enableHighAccuracy: true, timeout: 10000 },
        );
     } }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Cek Jalur ke ODP') }}</h1>
        <a href="{{ route('web.fiber-topology-map.index') }}" class="text-sm text-primary hover:underline">{{ __('Peta Topologi') }} &rarr;</a>
    </div>

    <p class="text-sm text-gray-500">{{ __('Referensi jalur untuk survei/pemasangan — bukan perhitungan biaya. Semua opsi rute yang ditemukan OSRM ditampilkan, terpendek ditandai "Rekomendasi".') }}</p>

    @if ($statusMessage)
        <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ $statusMessage }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Left: form --}}
        <div class="space-y-4">
            {{-- Customer link (optional) --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-2">
                <label class="block text-xs font-medium text-gray-700">{{ __('Tautkan ke pelanggan (opsional)') }}</label>
                @if ($customerId)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="inline-flex items-center gap-2 px-2 py-1 rounded-md bg-primary/10 text-primary">
                            {{ $prospectName }}
                            <button type="button" wire:click="clearCustomer" class="text-primary" aria-label="{{ __('Lepas tautan') }}">&times;</button>
                        </span>
                    </div>
                @else
                    <input type="text" wire:model.live.debounce.400ms="customerSearch" placeholder="{{ __('Cari nama / nomor HP…') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @if (count($customerMatches) > 0)
                        <ul class="border border-gray-100 rounded-md divide-y divide-gray-100 text-sm max-h-40 overflow-y-auto">
                            @foreach ($customerMatches as $m)
                                <li>
                                    <button type="button" wire:click="selectCustomer({{ $m->id }})" class="w-full text-left px-2 py-1.5 hover:bg-gray-50">
                                        {{ $m->name }} <span class="text-gray-400">— {{ $m->phone_number }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            @unless ($customerId)
                <div class="grid grid-cols-1 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Nama calon pelanggan') }}</label>
                        <input type="text" wire:model="prospectName" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @error('prospectName') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Alamat (opsional)') }}</label>
                        <input type="text" wire:model="prospectAddress" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    </div>
                </div>
            @endunless

            {{-- Location --}}
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('Latitude') }}</label>
                    <input type="text" wire:model.live.debounce.500ms="latitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('latitude') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('Longitude') }}</label>
                    <input type="text" wire:model.live.debounce.500ms="longitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                </div>
            </div>
            <button type="button" x-on:click="locate()" x-bind:disabled="geoBusy" class="text-xs px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">
                <span x-show="!geoBusy">{{ __('Ambil lokasi saya') }}</span>
                <span x-show="geoBusy" x-cloak>{{ __('Mengambil…') }}</span>
            </button>

            {{-- Candidate ODPs --}}
            <div class="border border-gray-200 rounded-md p-3 space-y-2">
                <label class="block text-xs font-medium text-gray-700">{{ __('ODP tujuan (kandidat terdekat)') }}</label>
                @if (count($candidates) === 0)
                    <p class="text-xs text-gray-400 italic">{{ __('Tetapkan lokasi calon pelanggan dulu untuk melihat ODP terdekat.') }}</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($candidates as $c)
                            <li>
                                <label class="flex items-center gap-2 text-sm px-2 py-1.5 rounded-md hover:bg-gray-50 cursor-pointer">
                                    <input type="radio" wire:model="targetOdpId" value="{{ $c['odp_id'] }}" class="text-primary focus:ring-primary">
                                    <span class="flex-1">{{ $c['code'] }} - {{ $c['name'] }}</span>
                                    <span class="text-xs text-gray-400">{{ $c['distance_km'] }} km</span>
                                    <span class="inline-block px-1.5 py-0.5 rounded-full text-[11px] text-white" style="background-color: {{ $c['zone_color'] }};">{{ $c['used_ports'] }}/{{ $c['total_ports'] }} · {{ $c['zone_label'] }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @error('targetOdpId') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <button type="button" wire:click="calculateRoutes" wire:loading.attr="disabled" wire:target="calculateRoutes"
                    class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                <span wire:loading.remove wire:target="calculateRoutes">{{ __('Hitung Rute') }}</span>
                <span wire:loading wire:target="calculateRoutes">{{ __('Menghitung…') }}</span>
            </button>
        </div>

        {{-- Right: map --}}
        <div wire:ignore
             x-data="odpRouteMap({ candidates: @js($candidates), lat: @js($latitude), lng: @js($longitude) })"
             class="rounded-md border border-gray-200 overflow-hidden">
            <div x-ref="map" class="h-[26rem] lg:h-full min-h-[26rem] w-full bg-gray-100" role="application" aria-label="{{ __('Peta cek jalur ke ODP') }}"></div>
        </div>
    </div>

    {{-- Route options --}}
    @if (count($routeOptions) > 0)
        <div class="border border-gray-200 rounded-md p-4 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700">{{ __('Opsi Rute') }} ({{ count($routeOptions) }})</h2>
            @foreach ($routeOptions as $i => $opt)
                <div class="border border-gray-100 rounded-md p-3 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium {{ $i === 0 ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700' }}">{{ $opt['label'] }}</span>
                        <span class="text-sm text-gray-700">
                            {{ $opt['distance_meters'] >= 1000 ? number_format($opt['distance_meters'] / 1000, 2).' km' : round($opt['distance_meters']).' m' }}
                        </span>
                        @if (! empty($opt['duration_seconds']))
                            <span class="text-xs text-gray-400">&approx; {{ round($opt['duration_seconds'] / 60) }} menit</span>
                        @endif
                        @if (! empty($opt['is_fallback']))
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ __('estimasi lurus — routing tidak tersedia') }}</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex-1 min-w-[14rem]">
                            <label class="block text-xs text-gray-500">{{ __('Catatan (opsional, mis. "Via Jalan A")') }}</label>
                            <input type="text" wire:model="routeNotes.{{ $i }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        </div>
                        <button type="button" wire:click="saveRoute({{ $i }})" wire:loading.attr="disabled" wire:target="saveRoute({{ $i }})"
                                class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">{{ __('Simpan Catatan') }}</button>
                    </div>
                    @error('prospectName') @if($i === 0) <span class="block text-xs text-red-600">{{ $message }}</span> @endif @enderror
                </div>
            @endforeach
        </div>
    @endif
</div>
