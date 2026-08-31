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
                        <p class="text-sm font-medium text-gray-700">{{ __('Kabel') }} #{{ $cable->id }}</p>
                        <p class="text-xs text-gray-500 mb-2">{{ $cable->total_cores }} core, {{ $cable->tube_count }} tube</p>
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
                <p class="text-xs font-semibold uppercase text-gray-400">{{ __('Kabel Keluar / Titik Anak') }}</p>
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
                                    <p class="text-xs text-gray-500 mb-2">{{ __('via Kabel') }} #{{ $cable->id }} ({{ $cable->total_cores }} core)</p>
                                    @include('livewire.network.partials.core-map', ['cores' => $cable->cores, 'colorService' => $colorService])
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Accessories loss comparison --}}
    <div class="border border-gray-200 rounded-md overflow-x-auto">
        <h2 class="text-sm font-semibold text-gray-700 p-4 pb-0">{{ __('Aksesori di Jalur Ini') }}</h2>
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
