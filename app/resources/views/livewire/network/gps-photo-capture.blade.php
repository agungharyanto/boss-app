<div class="space-y-6" x-data="{ geoError: '' }">
    @if (session('gps-photo-status'))
        <div class="p-3 bg-green-50 border border-green-200 text-green-800 text-sm rounded-md">
            {{ session('gps-photo-status') }}
        </div>
    @endif

    {{-- GPS --}}
    <div class="p-4 border border-gray-200 rounded-md space-y-3">
        <h3 class="text-sm font-semibold text-gray-800">{{ __('Lokasi GPS') }}</h3>

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
        >
            {{ __('Ambil lokasi saya') }}
        </button>
        <p x-show="geoError" x-text="geoError" class="text-sm text-red-600"></p>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Latitude') }}</label>
                <input type="text" wire:model="latitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('latitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Longitude') }}</label>
                <input type="text" wire:model="longitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('longitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <button wire:click="saveLocation" class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90">
            {{ __('Simpan Lokasi') }}
        </button>
    </div>

    {{-- Photos --}}
    <div class="p-4 border border-gray-200 rounded-md space-y-3">
        <h3 class="text-sm font-semibold text-gray-800">{{ __('Foto') }}</h3>

        <input type="file" wire:model="newPhotos" multiple accept="image/*" capture="environment" class="block w-full text-sm">
        @error('newPhotos.*') <span class="text-sm text-red-600">{{ $message }}</span> @enderror

        @if (count($newPhotos) > 0)
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                @foreach ($newPhotos as $index => $file)
                    <div class="relative">
                        <img src="{{ $file->temporaryUrl() }}" class="w-full h-24 object-cover rounded-md border border-gray-200">
                        <button
                            type="button"
                            wire:click="removeNewPhoto({{ $index }})"
                            class="absolute top-1 right-1 bg-white/80 rounded-full w-5 h-5 text-xs text-red-600 hover:bg-white"
                        >&times;</button>
                    </div>
                @endforeach
            </div>

            <button wire:click="uploadPhotos" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                {{ __('Unggah Foto') }}
            </button>
        @endif

        @if ($photos->isNotEmpty())
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-3 pt-3 border-t border-gray-100">
                @foreach ($photos as $photo)
                    <div class="relative">
                        <img src="{{ route('web.fiber-node-photos.show', $photo->id) }}" class="w-full h-24 object-cover rounded-md border border-gray-200">
                        <button
                            type="button"
                            wire:click="deletePhoto({{ $photo->id }})"
                            wire:confirm="{{ __('Hapus foto ini?') }}"
                            class="absolute top-1 right-1 bg-white/80 rounded-full w-5 h-5 text-xs text-red-600 hover:bg-white"
                        >&times;</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
