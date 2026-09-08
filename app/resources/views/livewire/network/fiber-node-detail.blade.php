@php
    $isOdp = $target instanceof \App\Models\Odp;
    $label = $isOdp ? "{$target->code} - {$target->name}" : ($target->local_label ?? $target->node_type->label());
    $typeLabel = $isOdp ? 'ODP' : $target->node_type->label();
    $hasGps = $target->latitude !== null && $target->longitude !== null;
    $mapsUrl = $hasGps ? "https://www.google.com/maps/dir/?api=1&destination={$target->latitude},{$target->longitude}" : null;
@endphp
<div class="p-6 max-w-6xl mx-auto space-y-6">
    <div class="flex items-start justify-between">
        <div>
            <span class="inline-block text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-primary/10 text-primary mb-1">{{ $typeLabel }}</span>
            <h1 class="text-2xl font-semibold text-gray-800">{{ $label }}</h1>
            <div class="mt-1 flex items-center gap-3 text-sm text-gray-500">
                @if ($hasGps)
                    <a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-primary hover:underline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 103 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 002.273 1.765 11.842 11.842 0 00.976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z" clip-rule="evenodd" />
                        </svg>
                        {{ $target->latitude }}, {{ $target->longitude }} — {{ __('Buka arah di Google Maps') }}
                    </a>
                @else
                    <span class="text-gray-400">{{ __('Belum ada koordinat GPS') }}</span>
                @endif
            </div>
        </div>
        <a
            href="{{ $isOdp ? route('web.odps.edit', $target->id) : route('web.fiber-nodes.edit', $target->id) }}"
            class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
        >{{ __('Edit') }}</a>
    </div>

    <div class="flex gap-3 text-sm">
        <span class="px-2 py-1 rounded-md bg-gray-100 text-gray-700">{{ __('Redaman Masuk') }}: <strong>{{ $target->loss_in_db ?? '-' }} dB</strong></span>
        <span class="px-2 py-1 rounded-md bg-gray-100 text-gray-700">{{ __('Redaman Keluar') }}: <strong>{{ $target->loss_out_db ?? '-' }} dB</strong></span>
    </div>

    {{-- Splice diagram — incoming cables | center node/splitter | outgoing children --}}
    <div class="border border-gray-200 rounded-md p-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">{{ __('Diagram Splice') }}</h2>

        @if (session('cable-status'))
            <div class="mb-3 p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('cable-status') }}</div>
        @endif
        @if (session('cable-error'))
            <div class="mb-3 p-2 bg-red-50 border border-red-200 text-red-800 text-xs rounded-md">{{ session('cable-error') }}</div>
        @endif

        <div class="flex flex-col lg:flex-row items-stretch gap-4 min-w-[720px]">
            {{-- Incoming --}}
            <div class="lg:w-1/4 space-y-3">
                <p class="text-xs font-semibold uppercase text-gray-400">{{ __('Kabel Masuk') }}</p>
                @forelse ($incoming_cables as $cable)
                    <div class="border border-gray-200 rounded-md p-3 bg-gray-50">
                        <p class="text-sm font-medium text-gray-700">{{ $topologyService->describeCable($cable) }}</p>
                        <p class="text-xs text-gray-500 mb-2">{{ $cable->tube_count }} tube</p>
                        @include('livewire.network.partials.core-map', ['cores' => $cable->cores, 'colorService' => $colorService])
                        @if (auth()->user()->can('network_infrastructure.manage'))
                            <button
                                type="button"
                                wire:click="deleteCable({{ $cable->id }})"
                                wire:confirm="{{ __('Hapus kabel ini? Semua core, waypoint rute, penempatan port, dan aksesori yang terkait kabel ini ikut terhapus permanen. Kabel yang masih punya splice core-to-core aktif tidak bisa dihapus — hapus splice-nya dulu.') }}"
                                class="mt-2 text-xs text-red-600 hover:underline"
                            >{{ __('Hapus') }}</button>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">{{ __('Tidak ada kabel masuk.') }}</p>
                @endforelse
            </div>

            {{-- Center --}}
            <div class="lg:w-1/3 flex items-center justify-center">
                <div class="border-2 border-primary rounded-lg p-4 bg-primary/5 text-center w-full">
                    <span class="inline-block text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-primary text-white mb-1">{{ $typeLabel }}</span>
                    <p class="font-semibold text-gray-800">{{ $label }}</p>

                    @if ($splitters->isNotEmpty())
                        <div class="mt-3 space-y-2">
                            @foreach ($splitters as $splitter)
                                <div class="border border-gray-200 rounded-md p-2 bg-white text-left">
                                    <p class="text-sm font-medium text-gray-700">{{ __('Splitter') }} {{ $splitter->ratio }}</p>
                                    @if ($splitter->model)
                                        <p class="text-xs text-gray-500">{{ $splitter->model }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Outgoing / children --}}
            <div class="lg:w-5/12 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase text-gray-400">{{ __('Kabel Keluar / Titik Anak') }}</p>
                    @if (auth()->user()->can('network_infrastructure.manage'))
                        <a
                            href="{{ $isOdp ? route('web.odps.cables.create', $target->id) : route('web.fiber-nodes.cables.create', $target->id) }}"
                            class="text-xs px-2 py-1 border border-gray-300 rounded-md hover:bg-gray-50 whitespace-nowrap"
                        >+ {{ __('Tambah kabel keluar') }}</a>
                    @endif
                </div>
                @if ($children === [])
                    <p class="text-sm text-gray-400 italic">{{ __('Tidak ada percabangan.') }}</p>
                @else
                    {{-- Never draw every branch inside one big diagram — one
                         compact card per child, linking to its own detail
                         page, per the sprint brief. --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($children as $index => $child)
                            @php $cable = $outgoing_cables->firstWhere('id', $child['cable_id']); @endphp
                            <div class="border border-gray-200 rounded-md bg-white hover:border-primary hover:shadow-sm transition">
                                <a
                                    href="{{ $child['type'] === \App\Models\FiberNode::class ? route('web.fiber-nodes.detail', $child['id']) : route('web.odps.detail', $child['id']) }}"
                                    class="block p-3"
                                >
                                    <p class="text-sm font-medium text-gray-700">{{ $child['label'] }}</p>
                                    @if ($cable)
                                        <p class="text-xs text-gray-500 mb-2">{{ __('via') }} {{ $topologyService->describeCable($cable) }}</p>
                                        @include('livewire.network.partials.core-map', ['cores' => $cable->cores, 'colorService' => $colorService])
                                    @endif
                                </a>
                                @if ($cable && auth()->user()->can('network_infrastructure.manage'))
                                    <div class="px-3 pb-2 -mt-1">
                                        <button
                                            type="button"
                                            wire:click="deleteCable({{ $cable->id }})"
                                            wire:confirm="{{ __('Hapus kabel ini? Semua core, waypoint rute, penempatan port, dan aksesori yang terkait kabel ini ikut terhapus permanen. Splice core-to-core aktif harus dihapus lebih dulu. (Titik anak di ujung kabel TIDAK ikut terhapus.)') }}"
                                            class="text-xs text-red-600 hover:underline"
                                        >{{ __('Hapus kabel') }}</button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- v0.16.1 Bagian C — "Koneksi Core": satu section per titik, kolom
         per-tube (jumlah kolom ikut tube_count kabel, dinamis), badge
         warna tube di header kolom. Untuk OTB, section ini SEKALIGUS jadi
         "Simulasi Port" lama (badge nomor port + tujuan per core + form
         assign di bawah) — tidak lagi dua section terpisah. --}}
    <div class="border border-gray-200 rounded-md p-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-700">{{ __('Koneksi Core') }}</h2>
            @if ($isOtb)
                @php $used = collect($portSimulation)->whereNotNull('core')->count(); @endphp
                <span class="text-xs text-gray-400">{{ __('Port terpakai') }}: {{ $used }} / {{ $portCount ?: '—' }}</span>
            @endif
        </div>

        @if ($isOtb && session('port-status'))
            <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('port-status') }}</div>
        @endif

        @if ($coreGrid === [])
            <p class="text-sm text-gray-400 italic">{{ __('Tidak ada kabel yang terhubung ke titik ini.') }}</p>
        @else
            @foreach ($coreGrid as $group)
                <div class="border border-gray-100 rounded-md">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-gray-50 border-b border-gray-100">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium">{{ $group['description'] }}</span>
                            <span class="text-gray-500">— {{ $group['from_label'] }} &rarr; {{ $group['to_label'] }}</span>
                            <span class="text-xs text-gray-400">({{ $group['tube_count'] }} {{ __('tube') }} &times; {{ $group['cores_per_tube'] }})</span>
                        </div>
                        @if ($group['mappable'])
                            <a
                                href="{{ route('web.fiber-topology-map.index', ['cable' => $group['cable_id']]) }}"
                                wire:navigate
                                class="text-xs text-primary hover:underline whitespace-nowrap"
                            >{{ __('Lihat di peta') }}</a>
                        @else
                            <span class="text-xs text-gray-400 whitespace-nowrap" title="{{ __('Salah satu ujung kabel belum punya koordinat GPS') }}">{{ __('koordinat kurang') }}</span>
                        @endif
                    </div>

                    {{-- Kolom per-tube — flex-wrap supaya di HP jadi satu kolom
                         menumpuk, di layar lebar berjajar sesuai tube_count. --}}
                    <div class="p-3 flex flex-wrap gap-3">
                        @foreach ($group['tubes'] as $tube)
                            <div class="flex-1 min-w-[190px] border border-gray-100 rounded-md">
                                <div class="flex items-center gap-1.5 px-2 py-1.5 bg-gray-50 border-b border-gray-100 text-xs font-medium text-gray-600">
                                    <span class="inline-block w-3 h-3 rounded-sm border border-gray-300 shrink-0" style="background-color: {{ $tube['tube_hex'] ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna tube') }}: {{ $tube['tube_color'] ?? __('tidak diketahui') }}"></span>
                                    {{ __('Tube') }} {{ $tube['tube_number'] }}
                                    <span class="text-gray-400 font-normal">({{ $tube['tube_color'] ?? '?' }})</span>
                                </div>
                                <ul class="divide-y divide-gray-100">
                                    @foreach ($tube['cores'] as $core)
                                        <li class="px-2 py-1.5 text-xs">
                                            <div class="flex items-center gap-1.5">
                                                <span class="inline-block w-2.5 h-2.5 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $core['core_hex'] ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $core['core_color'] ?? __('tidak diketahui') }}"></span>
                                                <span class="text-gray-700">T{{ $core['tube_number'] }}/C{{ $core['core_number_in_tube'] }}</span>
                                                <span class="text-gray-400">{{ $core['core_color'] ?? '?' }}</span>
                                                @if ($isOtb && $core['port_number'] !== null)
                                                    <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium">{{ __('Port') }} {{ $core['port_number'] }}</span>
                                                @endif
                                            </div>
                                            @if ($isOtb && $core['port_number'] !== null && $core['destination'])
                                                <div class="mt-0.5 pl-4 text-gray-500">
                                                    &rarr;
                                                    @if ($core['connects_to_olt'])
                                                        <span class="inline-flex items-center px-1 py-0.5 rounded bg-indigo-100 text-indigo-800">{{ $core['destination'] }}</span>
                                                    @else
                                                        <span>{{ $core['destination'] }}</span>
                                                    @endif
                                                    @if ($core['port_note'])
                                                        <span class="text-gray-400 italic">— {{ $core['port_note'] }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($core['spliced_to'])
                                                <div class="mt-0.5 pl-4 text-emerald-700">
                                                    &#8652; {{ __('splice') }}: {{ $core['spliced_to'] }}
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif

        @if ($isOtb && $portCount === 0)
            <p class="text-sm text-amber-600 italic">{{ __('Jumlah port OTB belum diisi — atur lewat tombol Edit di atas sebelum bisa assign port.') }}</p>
        @endif

        @if ($isOtb && $portLogs->isNotEmpty())
            <div class="text-xs text-gray-500 space-y-1">
                <p class="font-semibold text-gray-400 uppercase">{{ __('3 Perubahan Terakhir') }}</p>
                @foreach ($portLogs as $log)
                    <p>
                        {{ $log->created_at?->diffForHumans() }} —
                        {{ optional($log->performedBy)->name ?? __('Sistem') }}:
                        {{ __('Core') }} #{{ $log->fiber_core_id }}
                        {{ $log->old_port_number !== null ? 'port '.$log->old_port_number : ($log->old_olt_label ?? __('lepas')) }}
                        &rarr;
                        {{ $log->new_port_number !== null ? 'port '.$log->new_port_number.($log->new_olt_label ? ' ('.$log->new_olt_label.')' : '') : __('lepas') }}
                    </p>
                @endforeach
            </div>
        @endif

        @if ($isOtb && auth()->user()->can('network_infrastructure.manage') && $portCount > 0 && $hasAnyAssignableCore)
            <div class="pt-3 border-t border-gray-200 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase text-gray-400">{{ __('Assign Port ke Core') }}</h3>
                    <button type="button" wire:click="openSwapModal" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Tukar Port') }}</button>
                </div>

                {{-- v0.16.1 Revisi E — cari baris (tetap dipakai untuk cari biasa) --}}
                <input
                    type="search"
                    wire:model.live.debounce.300ms="portSearch"
                    placeholder="{{ __('Cari: nomor port / nama tube / warna core / kabel') }}"
                    class="w-full sm:w-80 rounded-md border-gray-300 shadow-sm text-sm"
                    aria-label="{{ __('Cari baris core') }}"
                >

                <form wire:submit="saveAllPorts" class="space-y-3">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Core') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Tujuan') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs w-24">{{ __('Port') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('OLT (opsional) & catatan port') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($assignableCores as $core)
                                    @php
                                        $cHex = $colorService->hexForName($core['core_color']);
                                        $selOltId = ($oltDeviceInputs[$core['core_id']] ?? '') !== '' ? (int) $oltDeviceInputs[$core['core_id']] : null;
                                        $selPonCount = $selOltId !== null ? ($oltPonCounts[$selOltId] ?? null) : null;
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $cHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $core['core_color'] ?? __('tidak diketahui') }}"></span>
                                                <span>{{ $core['cable_description'] }} — Tube {{ $core['tube_number'] }} ({{ $core['tube_color'] ?? '?' }}) / Core {{ $core['core_number_in_tube'] }} ({{ $core['core_color'] ?? '?' }})</span>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-gray-500">{{ $core['destination'] }}</td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-1">
                                                <input type="number" min="1" max="{{ $portCount }}" wire:model="portInputs.{{ $core['core_id'] }}" class="w-16 rounded-md border-gray-300 shadow-sm text-sm" aria-label="{{ __('Nomor port untuk core ini') }}">
                                                <button type="button" wire:click="assignPort({{ $core['core_id'] }})" class="text-xs px-2 py-1 border border-gray-300 rounded-md hover:bg-gray-50" title="{{ __('Simpan baris ini saja') }}">{{ __('Simpan') }}</button>
                                            </div>
                                            @error('portInputs.'.$core['core_id']) <span class="block text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-1">
                                                <select wire:model.live="oltDeviceInputs.{{ $core['core_id'] }}" class="rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Perangkat OLT') }}">
                                                    <option value="">{{ __('— bukan OLT —') }}</option>
                                                    @foreach ($oltOptions as $olt)
                                                        <option value="{{ $olt['id'] }}">{{ $olt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                @if ($selPonCount)
                                                    <select wire:model="oltPonInputs.{{ $core['core_id'] }}" class="w-32 rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('PON port') }}">
                                                        <option value="">{{ __('-- PON --') }}</option>
                                                        @for ($p = 1; $p <= $selPonCount; $p++)
                                                            <option value="PON {{ $p }}">{{ __('PON') }} {{ $p }}</option>
                                                        @endfor
                                                    </select>
                                                @else
                                                    <input type="text" wire:model="oltPonInputs.{{ $core['core_id'] }}" placeholder="{{ __('PON 1 / catatan') }}" class="w-32 rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Label PON / catatan port') }}">
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-3 text-xs text-gray-400 italic">{{ __('Tidak ada core yang cocok dengan pencarian.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveAllPorts" class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                            <span wire:loading.remove wire:target="saveAllPorts">{{ __('Simpan Semua') }}</span>
                            <span wire:loading wire:target="saveAllPorts">{{ __('Menyimpan…') }}</span>
                        </button>
                        <p class="text-xs text-gray-400">{{ __('Kosongkan nomor port untuk melepas core. Label boleh diisi bebas (mis. "Backbone Lintas A") meski tanpa OLT. Untuk menukar penempatan port (antar core atau antar tube), pakai tombol "Tukar Port" di atas. Semua baris disimpan sekaligus — kalau ada yang error, tidak ada yang tersimpan.') }}</p>
                    </div>
                </form>
            </div>

            {{-- v0.16.1 Revisi 2 C — modal "Tukar Port" 2-tingkat (DI LUAR
                 <form> di atas). Tahap 1 pilih mode; tahap 2/3 sesuai mode. --}}
            @if ($showSwapModal)
                <div class="fixed inset-0 z-[1100] bg-black/40" wire:click="closeSwapModal" aria-hidden="true"></div>
                <div class="fixed inset-x-0 bottom-0 z-[1200] bg-white rounded-t-2xl shadow-2xl max-h-[85vh] overflow-y-auto
                            md:inset-0 md:m-auto md:h-fit md:max-w-lg md:rounded-2xl"
                     role="dialog" aria-modal="true" aria-label="{{ __('Tukar Port') }}">
                    <div class="flex items-center justify-between p-4 border-b border-gray-100">
                        <p class="text-base font-semibold text-gray-800">{{ __('Tukar Port') }}</p>
                        <button type="button" wire:click="closeSwapModal" class="w-8 h-8 inline-flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500 text-lg" aria-label="{{ __('Tutup') }}">&times;</button>
                    </div>

                    <div class="p-4 space-y-4">
                        {{-- Tahap 1 — pilih mode --}}
                        @if ($swapMode === '')
                            <p class="text-sm text-gray-600">{{ __('Pilih cara menukar:') }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <button type="button" wire:click="chooseSwapMode('core')" class="p-3 border border-gray-200 rounded-md text-left hover:border-primary hover:bg-primary/5">
                                    <span class="block text-sm font-medium text-gray-800">{{ __('Tukar Antar Core') }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ __('Pilih dua core individual, tukar penempatan portnya.') }}</span>
                                </button>
                                <button type="button" wire:click="chooseSwapMode('tube')" class="p-3 border border-gray-200 rounded-md text-left hover:border-primary hover:bg-primary/5">
                                    <span class="block text-sm font-medium text-gray-800">{{ __('Tukar Antar Tube') }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ __('Tukar seluruh tube — tiap core ditukar dengan core di posisi yang sama.') }}</span>
                                </button>
                            </div>
                        @endif

                        {{-- Mode: Antar Core --}}
                        @if ($swapMode === 'core')
                            <button type="button" wire:click="chooseSwapMode('')" class="text-xs text-primary hover:underline">&larr; {{ __('Ganti mode') }}</button>
                            <input type="search" wire:model.live.debounce.300ms="swapCoreSearch" placeholder="{{ __('Cari core (tube / warna / port / kabel)') }}" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Core sumber') }}</label>
                                <select wire:model="swapSourceCore" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    <option value="">{{ __('-- Pilih core --') }}</option>
                                    @foreach ($swapCoreOptions as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('swapSourceCore') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Core tujuan') }}</label>
                                <select wire:model="swapTargetCore" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    <option value="">{{ __('-- Pilih core --') }}</option>
                                    @foreach ($swapCoreOptions as $opt)
                                        @if ((string) $opt['id'] !== $swapSourceCore)
                                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('swapTargetCore') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="confirmSwapCores" @disabled($swapSourceCore === '' || $swapTargetCore === '') class="w-full px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">{{ __('Tukar Dua Core Ini') }}</button>
                        @endif

                        {{-- Mode: Antar Tube --}}
                        @if ($swapMode === 'tube')
                            <button type="button" wire:click="chooseSwapMode('')" class="text-xs text-primary hover:underline">&larr; {{ __('Ganti mode') }}</button>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Kabel') }}</label>
                                <select wire:model.live="swapCableId" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    <option value="">{{ __('-- Pilih kabel --') }}</option>
                                    @foreach ($swapCableOptions as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }} ({{ $opt['tube_count'] }} tube)</option>
                                    @endforeach
                                </select>
                                @error('swapCableId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>

                            @if ($swapCableId !== '' && $swapTubeCount > 0)
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Tube sumber') }}</label>
                                    <select wire:model.live="swapSourceTube" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <option value="">{{ __('-- Pilih tube --') }}</option>
                                        @for ($t = 1; $t <= $swapTubeCount; $t++)
                                            <option value="{{ $t }}">{{ __('Tube') }} {{ $t }}</option>
                                        @endfor
                                    </select>
                                    @error('swapSourceTube') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </div>

                                @if ($swapSourceTube !== '')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Tube tujuan') }}</label>
                                        <select wire:model="swapTargetTube" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="">{{ __('-- Pilih tube --') }}</option>
                                            @for ($t = 1; $t <= $swapTubeCount; $t++)
                                                @if ((string) $t !== $swapSourceTube)
                                                    <option value="{{ $t }}">{{ __('Tube') }} {{ $t }}</option>
                                                @endif
                                            @endfor
                                        </select>
                                        @error('swapTargetTube') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <p class="text-xs text-gray-400">{{ __('Tiap core di tube sumber ditukar dengan core di posisi yang sama di tube tujuan (T-x/C-1 ⇄ T-y/C-1, dst).') }}</p>
                                    <button type="button" wire:click="confirmSwapTubes" @disabled($swapTargetTube === '') class="w-full px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">{{ __('Tukar Seluruh Tube') }}</button>
                                @endif
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- v0.16.1 Bagian D — Assign Core-to-Core (splice continuity) untuk
         titik non-OTB (Closure/ODC/ODP). Perlu minimal 2 kabel berbeda
         yang menyentuh titik ini. --}}
    @if (! $isOtb)
        <div class="border border-gray-200 rounded-md p-4 space-y-4">
            <h2 class="text-sm font-semibold text-gray-700">{{ __('Assign Core-to-Core') }}</h2>

            @if (session('splice-status'))
                <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('splice-status') }}</div>
            @endif

            @if ($splices->isEmpty())
                <p class="text-sm text-gray-400 italic">{{ __('Belum ada splice core-to-core di titik ini.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Core Awal') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Core Akhir') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Redaman') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Catatan') }}</th>
                                @if (auth()->user()->can('network_infrastructure.manage'))
                                    <th class="px-3 py-2 text-right font-medium text-gray-500 text-xs">{{ __('Aksi') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($splices as $splice)
                                <tr>
                                    <td class="px-3 py-2 text-gray-700">
                                        {{ $topologyService->describeCable($splice->fromCore->fiberCable) }} · T{{ $splice->fromCore->tube_number }}/C{{ $splice->fromCore->core_number_in_tube }}
                                    </td>
                                    <td class="px-3 py-2 text-gray-700">
                                        {{ $topologyService->describeCable($splice->toCore->fiberCable) }} · T{{ $splice->toCore->tube_number }}/C{{ $splice->toCore->core_number_in_tube }}
                                    </td>
                                    <td class="px-3 py-2 text-gray-500">{{ $splice->loss_db !== null ? $splice->loss_db.' dB' : '-' }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $splice->note ?? '-' }}</td>
                                    @if (auth()->user()->can('network_infrastructure.manage'))
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" wire:click="removeSplice({{ $splice->id }})" wire:confirm="{{ __('Hapus splice ini?') }}" class="text-xs text-red-600 hover:underline">{{ __('Hapus') }}</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (auth()->user()->can('network_infrastructure.manage'))
                @if (! $canSplice)
                    <p class="text-xs text-gray-400">{{ __('Butuh minimal satu kabel MASUK dan satu kabel KELUAR di titik ini untuk membuat splice.') }}</p>
                @else
                    <form wire:submit="createSplice" class="pt-3 border-t border-gray-200 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">{{ __('Kabel Masuk') }}</label>
                            <select wire:model.live="spliceCableA" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                <option value="">{{ __('-- Pilih kabel masuk --') }}</option>
                                @foreach ($spliceIncomingCableOptions as $opt)
                                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                            @error('spliceCableA') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">
                                {{ __('Tube / Core sisi masuk') }}
                                @if ($spliceCableA !== '')
                                    <span class="text-gray-400 font-normal">({{ count($spliceCoreAOptions) }} {{ __('core tersedia') }})</span>
                                @endif
                            </label>
                            <select wire:model="spliceCoreA" @disabled($spliceCableA === '') class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm disabled:bg-gray-100">
                                <option value="">{{ $spliceCableA === '' ? __('pilih kabel dulu') : __('-- Pilih core --') }}</option>
                                @foreach ($spliceCoreAOptions as $opt)
                                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                            @if ($spliceCableA !== '' && count($spliceCoreAOptions) === 0)
                                <span class="block text-xs text-amber-600 mt-1">{{ __('Semua core kabel ini sudah dipakai splice lain di titik ini.') }}</span>
                            @endif
                            @error('spliceCoreA') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">{{ __('Kabel Keluar') }}</label>
                            <select wire:model.live="spliceCableB" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                <option value="">{{ __('-- Pilih kabel keluar --') }}</option>
                                @foreach ($spliceOutgoingCableOptions as $opt)
                                    @if ((string) $opt['id'] !== $spliceCableA)
                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @error('spliceCableB') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">
                                {{ __('Tube / Core sisi keluar') }}
                                @if ($spliceCableB !== '')
                                    <span class="text-gray-400 font-normal">({{ count($spliceCoreBOptions) }} {{ __('core tersedia') }})</span>
                                @endif
                            </label>
                            <select wire:model="spliceCoreB" @disabled($spliceCableB === '') class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm disabled:bg-gray-100">
                                <option value="">{{ $spliceCableB === '' ? __('pilih kabel dulu') : __('-- Pilih core --') }}</option>
                                @foreach ($spliceCoreBOptions as $opt)
                                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                            @if ($spliceCableB !== '' && count($spliceCoreBOptions) === 0)
                                <span class="block text-xs text-amber-600 mt-1">{{ __('Semua core kabel ini sudah dipakai splice lain di titik ini.') }}</span>
                            @endif
                            @error('spliceCoreB') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">{{ __('Redaman splice (dB, opsional)') }}</label>
                            <input type="text" inputmode="decimal" wire:model="spliceLoss" placeholder="0.10" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                            @error('spliceLoss') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">{{ __('Catatan (opsional)') }}</label>
                            <input type="text" wire:model="spliceNote" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                            @error('spliceNote') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="createSplice" class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                                <span wire:loading.remove wire:target="createSplice">{{ __('Sambungkan') }}</span>
                                <span wire:loading wire:target="createSplice">{{ __('Menyimpan…') }}</span>
                            </button>
                        </div>
                    </form>
                @endif
            @endif
        </div>
    @endif

    {{-- Accessories loss comparison --}}
    <div class="border border-gray-200 rounded-md">
        <div class="flex items-center justify-between gap-2 p-4 pb-0">
            <h2 class="text-sm font-semibold text-gray-700">{{ __('Aksesori di Jalur Ini') }}</h2>
            @if (auth()->user()->can('network_infrastructure.manage') && count($accessoryTargets) > 0)
                <button type="button" wire:click="$toggle('showAccessoryForm')" class="text-xs px-2 py-1 border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $showAccessoryForm ? __('Tutup') : '+ '.__('Tambah Aksesori') }}
                </button>
            @endif
        </div>

        @if (session('accessory-status'))
            <div class="mx-4 mt-3 p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('accessory-status') }}</div>
        @endif

        @if ($showAccessoryForm)
            <form wire:submit="addAccessory" class="m-4 p-3 border border-gray-200 rounded-md bg-gray-50 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Terpasang di') }} <span class="text-red-600">*</span></label>
                        <select wire:model.live="accTargetKey" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">{{ __('-- Pilih kabel / splitter --') }}</option>
                            @foreach ($accessoryTargets as $t)
                                <option value="{{ $t['key'] }}">{{ $t['label'] }}</option>
                            @endforeach
                        </select>
                        @error('accTargetKey') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Tipe Aksesori') }} <span class="text-red-600">*</span></label>
                        <select wire:model.live="accType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">{{ __('-- Pilih tipe --') }}</option>
                            @foreach ($accessoryTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        @error('accType') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Redaman Referensi (dB)') }}</label>
                        <input type="text" inputmode="decimal" wire:model="accExpectedLoss" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        <p class="text-xs text-gray-400 mt-0.5">{{ __('Prefill otomatis dari referensi — boleh diubah.') }}</p>
                        @error('accExpectedLoss') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">{{ __('Redaman Terukur (dB)') }} <span class="text-red-600">*</span></label>
                        <input type="text" inputmode="decimal" wire:model="accMeasuredLoss" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @error('accMeasuredLoss') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">{{ __('Lokasi / Catatan') }}</label>
                        <input type="text" wire:model="accLocation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @error('accLocation') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
                <button type="submit" wire:loading.attr="disabled" wire:target="addAccessory" class="px-3 py-1.5 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                    <span wire:loading.remove wire:target="addAccessory">{{ __('Simpan Aksesori') }}</span>
                    <span wire:loading wire:target="addAccessory">{{ __('Menyimpan…') }}</span>
                </button>
            </form>
        @endif

        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm mt-3">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Lokasi') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600">{{ __('Redaman Referensi') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600">{{ __('Redaman Terukur') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($accessories as $accessory)
                    @php
                        $diff = ($accessory->expected_loss_db !== null && $accessory->measured_loss_db !== null)
                            ? abs((float) $accessory->measured_loss_db - (float) $accessory->expected_loss_db)
                            : null;
                        $isWarning = $diff !== null && $diff > 2;
                    @endphp
                    <tr>
                        <td class="px-4 py-2">{{ $accessory->accessory_type->label() }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $accessory->location_note ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $accessory->expected_loss_db !== null ? $accessory->expected_loss_db.' dB' : '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $accessory->measured_loss_db !== null ? $accessory->measured_loss_db.' dB' : '-' }}</td>
                        <td class="px-4 py-2">
                            @if ($isWarning)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                    {{ __('Selisih') }} {{ number_format($diff, 2) }} dB — {{ __('periksa ulang') }}
                                </span>
                            @elseif ($diff !== null)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('Normal') }}</span>
                            @else
                                <span class="text-gray-400 text-xs">{{ __('Belum terukur') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">{{ __('Tidak ada aksesori di jalur ini.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
