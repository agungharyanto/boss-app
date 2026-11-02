<div class="p-4 sm:p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">Profil Perusahaan</h1>
    <p class="text-sm text-gray-500 mb-6">
        Identitas perusahaan per tenant. Dipakai untuk cetakan invoice, persyaratan pemasangan, dan pesan WhatsApp.
        Nomor CS boleh di-override per reseller lewat halaman detail reseller.
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-2 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if (! empty($missing))
        <div class="mb-6 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Beberapa data penting belum diisi:</p>
            <ul class="list-disc list-inside mt-1">
                @foreach ($missing as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
            <p class="mt-1 text-amber-700">Isi data di bawah agar dokumen dan pesan tidak menampilkan kontak kosong.</p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-8">
        {{-- Identitas --}}
        <div class="space-y-4 p-4 rounded-md border border-gray-200 bg-white">
            <h2 class="text-lg font-medium text-gray-800">Identitas</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Perusahaan (legal)</label>
                    <input type="text" wire:model="legalName" placeholder="PT Jaringan Nusantara"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('legalName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Brand</label>
                    <input type="text" wire:model="brandName" placeholder="NusaNet"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('brandName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Alamat</label>
                <textarea wire:model="address" rows="2" placeholder="Jl. Contoh No. 1"
                    class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                @error('address') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Kota</label>
                    <input type="text" wire:model="city"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('city') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Kode Pos</label>
                    <input type="text" wire:model="postalCode"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('postalCode') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">NPWP</label>
                    <input type="text" wire:model="npwp"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('npwp') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Kontak --}}
        <div class="space-y-4 p-4 rounded-md border border-gray-200 bg-white">
            <h2 class="text-lg font-medium text-gray-800">Kontak</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nomor CS (WhatsApp)</label>
                    <input type="text" wire:model="csWhatsapp" placeholder="0812-3456-7890"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('csWhatsapp') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nomor CS (Telepon)</label>
                    <input type="text" wire:model="csPhone"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('csPhone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" wire:model="email"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jam Operasional</label>
                    <input type="text" wire:model="operatingHours" placeholder="24 Jam"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('operatingHours') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Rekening Pembayaran --}}
        <div class="space-y-4 p-4 rounded-md border border-gray-200 bg-white">
            <h2 class="text-lg font-medium text-gray-800">Rekening Pembayaran</h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Bank</label>
                    <input type="text" wire:model="bankName" placeholder="BCA"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('bankName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nomor Rekening</label>
                    <input type="text" wire:model="bankAccountNumber"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('bankAccountNumber') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Atas Nama</label>
                    <input type="text" wire:model="bankAccountHolder"
                        class="block w-full rounded-md border-gray-300 bg-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('bankAccountHolder') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Logo --}}
        <div class="space-y-4 p-4 rounded-md border border-gray-200 bg-white">
            <h2 class="text-lg font-medium text-gray-800">Logo</h2>

            @if ($hasLogo)
                <div class="flex items-center gap-4">
                    <img src="{{ route('web.settings.company-logo') }}?v={{ now()->timestamp }}"
                        alt="Logo perusahaan" class="h-16 w-auto border border-gray-200 rounded bg-gray-50 p-1">
                    <button type="button" wire:click="removeLogo"
                        wire:confirm="Hapus logo perusahaan?"
                        class="text-sm text-red-600 hover:underline">Hapus logo</button>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unggah Logo (JPG/PNG/WEBP, maks 2 MB)</label>
                <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
                <p class="mt-1 text-xs text-gray-400">SVG tidak didukung. File lama akan diganti saat mengunggah yang baru.</p>
                @error('logo') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                <div wire:loading wire:target="logo" class="text-xs text-gray-500 mt-1">Mengunggah…</div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                Simpan Profil
            </button>
            @if ($lastUpdatedAt)
                <span class="text-xs text-gray-400">Terakhir diubah: {{ $lastUpdatedAt }}</span>
            @endif
        </div>
    </form>
</div>
