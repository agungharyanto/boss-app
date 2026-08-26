<div class="space-y-6">
    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Profil Saya') }}</h2>

        @if ($nameUpdated)
            <p class="mb-3 text-sm text-green-600">{{ __('Nama berhasil diperbarui.') }}</p>
        @endif

        <form wire:submit="updateName" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full max-w-sm rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }}</label>
                <input type="text" value="{{ $phone }}" disabled
                    class="mt-1 block w-full max-w-sm rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm">
                <p class="text-xs text-gray-500 mt-1">{{ __('Nomor HP adalah kredensial login, tidak bisa diubah sendiri — hubungi admin kalau perlu diganti.') }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Tipe') }}</label>
                <input type="text" value="{{ $typeLabel }}" disabled
                    class="mt-1 block w-full max-w-sm rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm">
            </div>

            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
                {{ __('Simpan Nama') }}
            </button>
        </form>
    </div>

    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Pelanggan yang Saya Referensikan') }}</h2>

        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status Registrasi') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tanggal') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($referrals as $customer)
                        <tr wire:key="referral-{{ $customer->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $customer->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $customer->registration_status->label() }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $customer->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-500">
                                {{ __('Belum ada pelanggan yang Anda referensikan.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ __('Rekap Komisi') }}</h2>
        <p class="text-sm text-gray-500">{{ __('Akan tersedia di update berikutnya.') }}</p>
    </div>
</div>
