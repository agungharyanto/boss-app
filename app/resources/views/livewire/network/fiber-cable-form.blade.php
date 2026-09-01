{{--
    v0.16.0 Langkah 5 — FiberCableForm. Built with the ui-ux-pro-max Skill
    from the start: required-field markers, numeric input types, section
    headings, wire:loading submit feedback, colour swatches (not text
    alone) for the generated cores.
--}}
<div class="p-6 max-w-3xl mx-auto space-y-6">
    <div>
        <a href="{{ $sourceType === \App\Models\FiberNode::class ? route('web.fiber-nodes.detail', $sourceId) : route('web.odps.detail', $sourceId) }}" class="text-sm text-primary hover:underline">&larr; {{ __('Kembali ke detail titik') }}</a>
        <h1 class="text-2xl font-semibold text-gray-800 mt-1">{{ __('Tambah Kabel Keluar') }}</h1>
        <p class="text-sm text-gray-500">{{ __('Dari') }}: <span class="font-medium text-gray-700">{{ $sourceLabel }}</span></p>
    </div>

    @if (session('status'))
        <div class="p-3 bg-green-50 border border-green-200 text-green-800 text-sm rounded-md">{{ session('status') }}</div>
    @endif

    @if ($createdCableId === null)
        <p class="text-sm text-gray-500">{{ __('Tanda') }} <span class="text-red-600">*</span> {{ __('menandakan field wajib diisi.') }}</p>

        <form wire:submit="save" class="space-y-4 p-4 border border-gray-200 rounded-md bg-gray-50">
            <div>
                <label for="cable-to" class="block text-sm font-medium text-gray-700">{{ __('Titik Tujuan') }} <span class="text-red-600">*</span></label>
                <select id="cable-to" wire:model="toKey" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Pilih titik --') }}</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate['type'] }}#{{ $candidate['id'] }}">{{ $candidate['label'] }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1">{{ __('Hanya titik yang belum menjadi anak dari titik ini yang tampil.') }}</p>
                @error('toKey') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label for="cable-total" class="block text-sm font-medium text-gray-700">{{ __('Jumlah Core') }} <span class="text-red-600">*</span></label>
                    <input id="cable-total" type="number" min="2" step="2" wire:model="totalCores" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-400 mt-1">{{ __('Harus genap.') }}</p>
                    @error('totalCores') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="cable-tubes" class="block text-sm font-medium text-gray-700">{{ __('Jumlah Tube') }} <span class="text-red-600">*</span></label>
                    <input id="cable-tubes" type="number" min="1" wire:model="tubeCount" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('tubeCount') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="cable-cpt" class="block text-sm font-medium text-gray-700">{{ __('Core per Tube') }} <span class="text-red-600">*</span></label>
                    <input id="cable-cpt" type="number" min="1" wire:model="coresPerTube" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('coresPerTube') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500">{{ __('Jumlah tube × core per tube harus sama dengan jumlah core.') }}</p>

            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ __('Buat Kabel & Generate Core') }}</span>
                <span wire:loading wire:target="save">{{ __('Membuat…') }}</span>
            </button>
        </form>
    @else
        {{-- Review — generated cores, colours overridable --}}
        <div class="p-4 border border-gray-200 rounded-md">
            <h2 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Core Ter-generate') }} — {{ __('Kabel') }} #{{ $createdCableId }}</h2>
            <p class="text-xs text-gray-500 mb-4">{{ __('Warna mengikuti siklus TIA/EIA-598-C. Ubah manual per core bila kondisi lapangan berbeda, lalu simpan.') }}</p>

            <form wire:submit="saveCoreColors">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Tube') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Core') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Warna Tube') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500 text-xs">{{ __('Warna Core') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($cores as $core)
                                @php
                                    $tubeHex = $colorService->hexForName($coreEdits[$core->id]['tube'] ?? $core->tube_color);
                                    $coreHex = $colorService->hexForName($coreEdits[$core->id]['core'] ?? $core->core_color);
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 text-gray-700">{{ $core->tube_number }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $core->core_number_in_tube }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $tubeHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna tube') }}: {{ $coreEdits[$core->id]['tube'] ?? $core->tube_color ?? __('tidak diketahui') }}"></span>
                                            <input type="text" wire:model="coreEdits.{{ $core->id }}.tube" class="w-28 rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Warna tube core') }} {{ $core->tube_number }}/{{ $core->core_number_in_tube }}">
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block w-3 h-3 rounded-full border border-gray-300 shrink-0" style="background-color: {{ $coreHex ?? '#D1D5DB' }};" role="img" aria-label="{{ __('Warna core') }}: {{ $coreEdits[$core->id]['core'] ?? $core->core_color ?? __('tidak diketahui') }}"></span>
                                            <input type="text" wire:model="coreEdits.{{ $core->id }}.core" class="w-28 rounded-md border-gray-300 shadow-sm text-xs" aria-label="{{ __('Warna core') }} {{ $core->tube_number }}/{{ $core->core_number_in_tube }}">
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center gap-2 mt-4">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveCoreColors" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="saveCoreColors">{{ __('Simpan Warna') }}</span>
                        <span wire:loading wire:target="saveCoreColors">{{ __('Menyimpan…') }}</span>
                    </button>
                    <a href="{{ $sourceType === \App\Models\FiberNode::class ? route('web.fiber-nodes.detail', $sourceId) : route('web.odps.detail', $sourceId) }}" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Selesai') }}</a>
                </div>
            </form>
        </div>
    @endif
</div>
