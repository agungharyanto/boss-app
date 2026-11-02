<div class="p-4 sm:p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">Syarat &amp; Ketentuan Pemasangan</h1>
    <p class="text-sm text-gray-500 mb-6">
        Teks yang dibaca &amp; disetujui pelanggan sebelum tanda tangan saat pemasangan selesai.
        Setiap simpan membuat <span class="font-medium">versi baru</span> — versi lama tetap tersimpan.
        @if ($activeVersion) Versi aktif saat ini: <span class="font-medium">v{{ $activeVersion }}</span>. @endif
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-2 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    {{-- Daftar variabel yang bisa disisipkan --}}
    <div class="mb-4 p-4 rounded-md border border-gray-200 bg-gray-50">
        <p class="text-sm font-medium text-gray-700 mb-2">Variabel yang bisa disisipkan:</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($variables as $variable)
                <button type="button" wire:click="insertVariable('{{ $variable }}')"
                    class="px-2 py-1 text-xs font-mono rounded border border-gray-300 bg-white text-indigo-700 hover:bg-indigo-50">
                    {&#123;{{ $variable }}&#125;}
                </button>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-400">
            Variabel di luar daftar ini akan ditolak saat menyimpan. Variabel disisipkan di akhir teks.
        </p>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Isi Teks <span class="text-red-600">*</span>
            </label>
            <textarea wire:model="body" rows="16"
                class="block w-full rounded-md border-gray-300 bg-white shadow-sm font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
            @error('body') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                Simpan sebagai Versi Baru
            </button>
            <button type="button" wire:click="previewTerms"
                class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50">
                Pratinjau (data contoh)
            </button>
            @if ($loadedFromVersion)
                <span class="text-xs text-gray-400">Dimuat dari versi v{{ $loadedFromVersion }}</span>
            @endif
        </div>
    </form>

    @if ($preview !== null)
        <div class="mt-6 p-4 rounded-md border border-indigo-200 bg-indigo-50">
            <p class="text-sm font-medium text-indigo-800 mb-2">Pratinjau (variabel diisi data contoh):</p>
            <pre class="whitespace-pre-wrap text-sm text-gray-800 font-sans">{{ $preview }}</pre>
        </div>
    @endif

    {{-- Riwayat versi --}}
    <div class="mt-8">
        <h2 class="text-lg font-medium text-gray-800 mb-3">Riwayat Versi</h2>
        @if ($history->isEmpty())
            <p class="text-sm text-gray-400">Belum ada versi tersimpan.</p>
        @else
            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-600">Versi</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600">Dibuat oleh</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600">Waktu</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($history as $terms)
                            <tr>
                                <td class="px-3 py-2">
                                    v{{ $terms->version }}
                                    @if ($terms->version === $activeVersion)
                                        <span class="ml-1 text-xs text-green-600">(aktif)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-700">{{ $terms->createdBy?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $terms->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" wire:click="useVersion({{ $terms->id }})"
                                        class="text-indigo-600 hover:underline">Pakai ulang versi ini</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
