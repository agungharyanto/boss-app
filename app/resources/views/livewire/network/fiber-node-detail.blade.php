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

        <div class="flex flex-col lg:flex-row items-stretch gap-4 min-w-[720px]">
            {{-- Incoming --}}
            <div class="lg:w-1/4 space-y-3">
                <p class="text-xs font-semibold uppercase text-gray-400">{{ __('Kabel Masuk') }}</p>
                @forelse ($incoming_cables as $cable)
                    <div class="border border-gray-200 rounded-md p-3 bg-gray-50">
                        <p class="text-sm font-medium text-gray-700">{{ $topologyService->describeCable($cable) }}</p>
                        <p class="text-xs text-gray-500 mb-2">{{ $cable->tube_count }} tube</p>
                        @include('livewire.network.partials.core-map', ['cores' => $cable->cores, 'colorService' => $colorService])
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
                            <a
                                href="{{ $child['type'] === \App\Models\FiberNode::class ? route('web.fiber-nodes.detail', $child['id']) : route('web.odps.detail', $child['id']) }}"
                                class="block border border-gray-200 rounded-md p-3 bg-white hover:border-primary hover:shadow-sm transition"
                            >
                                <p class="text-sm font-medium text-gray-700">{{ $child['label'] }}</p>
                                @if ($cable)
                                    <p class="text-xs text-gray-500 mb-2">{{ __('via') }} {{ $topologyService->describeCable($cable) }}</p>
                                    @include('livewire.network.partials.core-map', ['cores' => $cable->cores, 'colorService' => $colorService])
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- v0.16.0 Langkah 8/9 — core connections, grouped per cable. The
         "Lihat di peta" link is one per CABLE (one physical route), not
         one per core — core colours stay a table concern only. --}}
    <div class="border border-gray-200 rounded-md p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700">{{ __('Koneksi Core') }}</h2>
        @if ($coreConnections === [])
            <p class="text-sm text-gray-400 italic">{{ __('Tidak ada kabel yang terhubung ke titik ini.') }}</p>
        @else
            @foreach ($coreConnections as $group)
                <div class="border border-gray-100 rounded-md">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-gray-50 border-b border-gray-100">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium">{{ $group['description'] }}</span>
                            <span class="text-gray-500">— {{ $group['from_label'] }} &rarr; {{ $group['to_label'] }}</span>
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
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Tube / Core') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Warna core') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($group['cores'] as $core)
                                    @php $connHex = $colorService->hexForName($core['core_color']); @endphp
                                    <tr>
                                        <td class="px-3 py-2 text-gray-700">T{{ $core['tube_number'] }}/C{{ $core['core_number_in_tube'] }}</td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $connHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $core['core_color'] ?? __('tidak diketahui') }}"></span>
                                                <span>{{ $core['core_color'] ?? '?' }} ({{ __('Tube') }} {{ $core['tube_color'] ?? '?' }})</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- v0.16.0 Langkah 6/7 — OTB port patch simulation --}}
    @if ($isOtb)
        <div class="border border-gray-200 rounded-md p-4 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-700">{{ __('Simulasi Port') }}</h2>
                <span class="text-xs text-gray-400">{{ __('Kapasitas') }}: {{ $portCount ?: '—' }} {{ __('port') }}</span>
            </div>

            @if (session('port-status'))
                <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('port-status') }}</div>
            @endif

            @if ($portCount === 0)
                <p class="text-sm text-gray-400 italic">{{ __('Jumlah port OTB belum diisi — atur lewat tombol Edit di atas.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs w-16">{{ __('Port') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Isi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($portSimulation as $row)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-700">{{ $row['port'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($row['core'] === null)
                                            <span class="text-gray-400 italic">{{ __('belum dipatch') }}</span>
                                        @else
                                            @php $cHex = $colorService->hexForName($row['core']['core_color']); @endphp
                                            <span class="inline-flex items-center gap-1.5 flex-wrap">
                                                <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $cHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $row['core']['core_color'] ?? __('tidak diketahui') }}"></span>
                                                <span>{{ __('Core') }} {{ $row['core']['core_color'] ?? '?' }} ({{ __('Tube') }} {{ $row['core']['tube_color'] ?? '?' }})</span>
                                                <span class="text-gray-400">&rarr;</span>
                                                @if ($row['core']['connects_to_olt'])
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">{{ $row['core']['destination'] }}</span>
                                                @else
                                                    <span class="font-medium text-gray-700">{{ $row['core']['destination'] }}</span>
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($portLogs->isNotEmpty())
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

            @if (auth()->user()->can('network_infrastructure.manage') && count($assignableCores) > 0)
                <form wire:submit="saveAllPorts" class="pt-3 border-t border-gray-200 space-y-3">
                    <h3 class="text-xs font-semibold uppercase text-gray-400">{{ __('Assign Port ke Core') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Core') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Tujuan') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs w-24">{{ __('Port') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Sambung ke OLT (opsional)') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($assignableCores as $core)
                                    @php $cHex = $colorService->hexForName($core['core_color']); @endphp
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $cHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $core['core_color'] ?? __('tidak diketahui') }}"></span>
                                                <span>{{ $core['cable_description'] }} — T{{ $core['tube_number'] }}/C{{ $core['core_number_in_tube'] }} ({{ $core['core_color'] ?? '?' }})</span>
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
                                                <select wire:model="oltDeviceInputs.{{ $core['core_id'] }}" class="rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Perangkat OLT') }}">
                                                    <option value="">{{ __('— bukan OLT —') }}</option>
                                                    @foreach ($oltOptions as $olt)
                                                        <option value="{{ $olt['id'] }}">{{ $olt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" wire:model="oltPonInputs.{{ $core['core_id'] }}" placeholder="PON 1" class="w-24 rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Label PON port') }}">
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveAllPorts" class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                            <span wire:loading.remove wire:target="saveAllPorts">{{ __('Simpan Semua') }}</span>
                            <span wire:loading wire:target="saveAllPorts">{{ __('Menyimpan…') }}</span>
                        </button>
                        <p class="text-xs text-gray-400">{{ __('Kosongkan nomor port untuk melepas core. Semua baris disimpan sekaligus — kalau ada yang error, tidak ada yang tersimpan.') }}</p>
                    </div>
                </form>
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
