{{--
    v0.16.0 Core Network Infrastructure Management.

    Langkah 3: draft offline — every field change is written to
    localStorage (key unique per form) via a debounced window-level input
    listener. On mount, an existing draft newer than the form's own
    initial load is offered, never auto-applied. Cleared on a real
    successful save (the fiber-node-saved event).

    Langkah 5: create mode gained the Leaflet picker + "Ambil lokasi
    saya" + a photo picker + (for ODC) a splitter sub-form; polish pass
    via ui-ux-pro-max (required-field markers, numeric input types,
    section headings, wire:loading submit feedback). File inputs are
    excluded from the localStorage draft (a file input's .value is a fake
    path, not restorable).
--}}
<div
    class="p-6 max-w-3xl mx-auto"
    x-data="{
        draftKey: {{ $fiberNodeId ? \Illuminate\Support\Js::from('fiber_node_draft_'.$fiberNodeId) : \Illuminate\Support\Js::from('fiber_node_draft_new') }},
        draftAvailable: false,
        draftFields: {},
        init() {
            const raw = localStorage.getItem(this.draftKey);
            if (raw) {
                try {
                    this.draftFields = JSON.parse(raw);
                    this.draftAvailable = true;
                } catch (e) { localStorage.removeItem(this.draftKey); }
            }
            window.addEventListener('fiber-node-saved', () => localStorage.removeItem(this.draftKey));
        },
        saveDraft() {
            const data = {};
            this.$root.querySelectorAll('[wire\\:model], [wire\\:model\\.live]').forEach((el) => {
                if (el.type === 'file') { return; }
                const attr = el.hasAttribute('wire:model') ? 'wire:model' : 'wire:model.live';
                data[el.getAttribute(attr)] = el.value;
            });
            localStorage.setItem(this.draftKey, JSON.stringify(data));
        },
        applyDraft() {
            Object.entries(this.draftFields).forEach(([field, value]) => $wire.set(field, value));
            this.draftAvailable = false;
        },
        dismissDraft() {
            localStorage.removeItem(this.draftKey);
            this.draftAvailable = false;
        },
    }"
>
    <h1 class="text-2xl font-semibold text-gray-800 mb-1">
        {{ $fiberNodeId ? __('Edit Perangkat Passive') : __('Perangkat Passive Baru') }}
    </h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Tanda') }} <span class="text-red-600">*</span> {{ __('menandakan field wajib diisi.') }}
    </p>

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 text-sm rounded-md">
            {{ session('status') }}
        </div>
    @endif

    <div x-show="draftAvailable" class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md flex items-center justify-between">
        <span class="text-sm text-yellow-800">{{ __('Draft tersimpan ditemukan.') }}</span>
        <div class="space-x-2">
            <button type="button" x-on:click="applyDraft()" class="px-3 py-1 text-sm bg-primary text-white rounded-md hover:opacity-90">
                {{ __('Lanjutkan draft tersimpan?') }}
            </button>
            <button type="button" x-on:click="dismissDraft()" class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50">
                {{ __('Abaikan') }}
            </button>
        </div>
    </div>

    <form wire:submit="save" x-on:input.debounce.500ms="saveDraft()" class="space-y-6">
        {{-- Identitas titik --}}
        <fieldset class="space-y-4 p-4 border border-gray-200 rounded-md bg-gray-50">
            <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Identitas Titik') }}</legend>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label for="fn-type" class="block text-sm font-medium text-gray-700">{{ __('Tipe Titik') }} <span class="text-red-600">*</span></label>
                    <select id="fn-type" wire:model.live="nodeType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="otb">{{ __('OTB') }}</option>
                        <option value="closure">{{ __('Closure') }}</option>
                        <option value="odc">{{ __('ODC') }}</option>
                    </select>
                    @error('nodeType') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="fn-label" class="block text-sm font-medium text-gray-700">{{ __('Label') }}</label>
                    <input id="fn-label" type="text" wire:model="localLabel" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('localLabel') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label for="fn-parent" class="block text-sm font-medium text-gray-700">{{ __('Titik Induk (Parent)') }}</label>
                <select id="fn-parent" wire:model="parentId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Tidak ada --') }}</option>
                    @foreach ($parentOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @error('parentId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="fn-loss-in" class="block text-sm font-medium text-gray-700">
                        {{ __('Redaman Masuk (loss in, dB)') }}
                        @if ($nodeType === 'odc') <span class="text-red-600">*</span> @endif
                    </label>
                    <input id="fn-loss-in" type="text" inputmode="decimal" wire:model="lossInDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('lossInDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="fn-loss-out" class="block text-sm font-medium text-gray-700">
                        {{ __('Redaman Keluar (loss out, dB)') }}
                        @if ($nodeType === 'odc') <span class="text-red-600">*</span> @endif
                    </label>
                    <input id="fn-loss-out" type="text" inputmode="decimal" wire:model="lossOutDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('lossOutDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            @if ($nodeType === 'otb')
                <div>
                    <label for="fn-port-count" class="block text-sm font-medium text-gray-700">
                        {{ __('Jumlah Port') }} <span class="text-red-600">*</span>
                    </label>
                    <input id="fn-port-count" type="number" min="1" max="1000" wire:model="portCount" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="text-xs text-gray-400 mt-1">{{ __('Jumlah port fisik panel OTB ini — dipakai untuk Simulasi Port di halaman detail.') }}</p>
                    @error('portCount') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            @endif

            <div>
                <label for="fn-notes" class="block text-sm font-medium text-gray-700">{{ __('Catatan') }}</label>
                <textarea id="fn-notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                @error('notes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </fieldset>

        {{-- Splitter — hanya untuk ODC (OTB/Closure bukan titik splitting) --}}
        @if ($nodeType === 'odc')
            <fieldset class="space-y-4 p-4 border border-gray-200 rounded-md bg-gray-50">
                <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Splitter') }}</legend>
                <p class="text-xs text-gray-500">
                    {{ __('Opsional. Isi rasio untuk memasang splitter pada titik ini. Rasio bebas diketik — daftar hanya saran umum.') }}
                </p>

                @if ($splitters->isNotEmpty())
                    <ul class="divide-y divide-gray-200 border border-gray-200 rounded-md bg-white text-sm">
                        @foreach ($splitters as $splitter)
                            <li class="flex items-center justify-between px-3 py-2">
                                <span>{{ __('Splitter') }} {{ $splitter->ratio }}@if ($splitter->model) <span class="text-gray-500">— {{ $splitter->model }}</span>@endif</span>
                                <button type="button" wire:click="deleteSplitter({{ $splitter->id }})" wire:confirm="{{ __('Hapus splitter ini?') }}" class="text-red-600 hover:underline">{{ __('Hapus') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="fn-splitter-ratio" class="block text-sm font-medium text-gray-700">{{ __('Rasio') }}</label>
                        <input id="fn-splitter-ratio" type="text" list="splitter-ratio-suggestions" wire:model="splitterRatio" placeholder="1:8" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <datalist id="splitter-ratio-suggestions">
                            @foreach ($ratioSuggestions as $ratio)
                                <option value="{{ $ratio }}"></option>
                            @endforeach
                        </datalist>
                        @error('splitterRatio') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="fn-splitter-model" class="block text-sm font-medium text-gray-700">{{ __('Model') }}</label>
                        <input id="fn-splitter-model" type="text" wire:model="splitterModel" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('splitterModel') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
                <p class="text-xs text-gray-400">{{ __('Foto splitter dapat diunggah lewat bagian Foto di titik ini.') }}</p>
            </fieldset>
        @endif

        {{-- Lokasi + Foto — hanya di mode create (mode edit memakai widget GpsPhotoCapture di bawah) --}}
        @if ($fiberNodeId === null)
            <fieldset class="space-y-4 p-4 border border-gray-200 rounded-md bg-gray-50">
                <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Lokasi GPS') }}</legend>

                @include('livewire.network.partials.location-map')

                <div x-data="{ geoError: '' }">
                    <button
                        type="button"
                        x-on:click="
                            geoError = '';
                            if (!navigator.geolocation) { geoError = 'Browser ini tidak mendukung Geolocation API.'; return; }
                            navigator.geolocation.getCurrentPosition(
                                (pos) => {
                                    $wire.set('latitude', pos.coords.latitude.toFixed(7));
                                    $wire.set('longitude', pos.coords.longitude.toFixed(7));
                                },
                                (err) => { geoError = 'Gagal mengambil lokasi: ' + err.message; }
                            );
                        "
                        class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                    >{{ __('Ambil lokasi saya') }}</button>
                    <p x-show="geoError" x-text="geoError" class="text-sm text-red-600 mt-1"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="fn-lat" class="block text-sm font-medium text-gray-700">{{ __('Latitude') }}</label>
                        <input id="fn-lat" type="text" inputmode="decimal" wire:model="latitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('latitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="fn-lng" class="block text-sm font-medium text-gray-700">{{ __('Longitude') }}</label>
                        <input id="fn-lng" type="text" inputmode="decimal" wire:model="longitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('longitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="pt-2 border-t border-gray-200">
                    <p class="block text-sm font-medium text-gray-700 mb-2">{{ __('Foto') }}</p>
                    @include('livewire.network.partials.photo-picker')
                    @if (count($newPhotos) > 0)
                        <p class="text-xs text-gray-500 mt-2">{{ __('Foto akan tersimpan setelah titik ini berhasil dibuat.') }}</p>
                    @endif
                </div>
            </fieldset>
        @endif

        <div class="flex items-center gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ __('Simpan') }}</span>
                <span wire:loading wire:target="save">{{ __('Menyimpan…') }}</span>
            </button>
            <a href="{{ route('web.fiber-nodes.index') }}" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                {{ __('Kembali') }}
            </a>
        </div>
    </form>

    @if ($fiberNodeId !== null)
        <div class="mt-6">
            @livewire('network.gps-photo-capture', ['ownerType' => \App\Models\FiberNode::class, 'ownerId' => $fiberNodeId], key('gps-'.$fiberNodeId))
        </div>
    @endif
</div>
