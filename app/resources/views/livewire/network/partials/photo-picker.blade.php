{{--
    v0.16.0 Langkah 6 — two distinct photo sources feeding one previewed
    $newPhotos array (App\Livewire\Concerns\StagesPhotoUploads):
      • "Ambil foto"        — accept=image/* + capture=environment → opens
                               the rear camera directly on a phone.
      • "Pilih dari galeri"  — accept=image/* WITHOUT capture → the OS
                               file/photo picker.
    Each binds to its own staging property; the trait's updated* hook
    merges it into $newPhotos, so a camera shot and a gallery pick can
    both sit in the same preview grid before "Unggah".

    Host must `use StagesPhotoUploads` (provides cameraPhotos/galleryPhotos/
    newPhotos/removeNewPhoto) and validate `newPhotos.*` as image/max in
    its own rules().
--}}
<div class="space-y-3">
    <div class="flex flex-wrap gap-2">
        <label class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M1 8a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 018.07 3h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0016.07 6H17a2 2 0 012 2v7a2 2 0 01-2 2H3a2 2 0 01-2-2V8zm13.5 3a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM10 14a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
            </svg>
            {{ __('Ambil foto') }}
            <input type="file" wire:model="cameraPhotos" accept="image/*" capture="environment" multiple class="sr-only">
        </label>
        <label class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd" />
            </svg>
            {{ __('Pilih dari galeri') }}
            <input type="file" wire:model="galleryPhotos" accept="image/*" multiple class="sr-only">
        </label>
    </div>

    <div wire:loading wire:target="cameraPhotos,galleryPhotos" class="text-xs text-gray-500">{{ __('Mengunggah…') }}</div>
    @error('cameraPhotos.*') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
    @error('galleryPhotos.*') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
    @error('newPhotos.*') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror

    @if (count($newPhotos) > 0)
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
            @foreach ($newPhotos as $index => $file)
                <div class="relative">
                    <img src="{{ $file->temporaryUrl() }}" alt="{{ __('Pratinjau foto') }} {{ $index + 1 }}" class="w-full h-24 object-cover rounded-md border border-gray-200">
                    <button type="button" wire:click="removeNewPhoto({{ $index }})" class="absolute top-1 right-1 bg-white/80 rounded-full w-5 h-5 text-xs text-red-600 hover:bg-white" aria-label="{{ __('Hapus pratinjau foto') }}">&times;</button>
                </div>
            @endforeach
        </div>
    @endif
</div>
