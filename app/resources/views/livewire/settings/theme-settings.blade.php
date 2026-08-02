<div class="p-6 max-w-lg mx-auto" x-data="{
    primary: @entangle('primaryColor'),
    text: @entangle('textColor'),
    preview(cssVar, value) {
        document.documentElement.style.setProperty(cssVar, value);
    },
}">
    <h1 class="text-2xl font-semibold mb-6" style="color: var(--color-text)">Pengaturan Tema</h1>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Warna Utama (Primary)</label>
            <div class="flex items-center gap-3">
                <input
                    type="color"
                    x-model="primary"
                    x-on:input="preview('--color-primary', $event.target.value)"
                    class="h-10 w-16 rounded border-gray-300 cursor-pointer"
                >
                <span class="text-sm text-gray-500" x-text="primary"></span>
            </div>
            @error('primaryColor') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Warna Teks</label>
            <div class="flex items-center gap-3">
                <input
                    type="color"
                    x-model="text"
                    x-on:input="preview('--color-text', $event.target.value)"
                    class="h-10 w-16 rounded border-gray-300 cursor-pointer"
                >
                <span class="text-sm text-gray-500" x-text="text"></span>
            </div>
            @error('textColor') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="p-4 rounded-md border border-gray-200" x-bind:style="`background-color: ${primary}1a`">
            <p class="text-sm" x-bind:style="`color: ${text}`">Contoh pratinjau — teks ini pakai warna teks Anda.</p>
            <button type="button" class="mt-2 px-3 py-1.5 rounded text-white text-sm" x-bind:style="`background-color: ${primary}`">
                Contoh tombol primary
            </button>
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            Simpan Tema
        </button>
    </form>
</div>
