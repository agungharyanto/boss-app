<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Rate Komisi') }}</h1>
    </div>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Konfigurasi rate komisi per Profil PPP. Komisi hanya untuk paket bulanan PPP — Hotspot/Token pakai skema terpisah.') }}
    </p>

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama paket...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Paket PPP') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Harga Jual') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Komisi Per Bulan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Skema X-Kali') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Komisi Titip') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($packages as $package)
                    @php($rate = $package->commissionRate)
                    <tr wire:key="pkg-{{ $package->id }}">
                        @if ($editingPackageId === $package->id)
                            <td colspan="7" class="px-4 py-3 bg-gray-50">
                                <form wire:submit="saveRate" class="space-y-3">
                                    <div class="text-sm font-medium text-gray-800">
                                        {{ $package->name }}
                                        <span class="text-xs font-normal text-gray-500">
                                            — {{ $rate ? __('Edit rate') : __('Atur rate baru') }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">{{ __('Komisi Per Bulan') }}</label>
                                            <input type="text" inputmode="decimal" wire:model="recurringAmount"
                                                placeholder="{{ __('kosongkan jika tidak dipakai') }}"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('recurringAmount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">{{ __('Komisi Titip') }}</label>
                                            <input type="text" inputmode="decimal" wire:model="titipAmount"
                                                placeholder="{{ __('kosongkan jika tidak dipakai') }}"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('titipAmount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                    </div>

                                    <fieldset class="border border-gray-200 rounded-md p-3">
                                        <legend class="text-xs font-medium text-gray-500 px-1">{{ __('Skema X-Kali (opsional, isi berpasangan)') }}</legend>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">{{ __('Komisi per Pembayaran') }}</label>
                                                <input type="text" inputmode="decimal" wire:model="limitedCountAmount"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                                @error('limitedCountAmount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">{{ __('Jumlah Kali Pembayaran') }}</label>
                                                <input type="text" inputmode="numeric" wire:model="limitedCountTimes"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                                @error('limitedCountTimes') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                            </div>
                                        </div>
                                    </fieldset>

                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="rateIsActive"> {{ __('Rate aktif') }}
                                    </label>

                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $package->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ number_format((float) $package->sell_price, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                {{ $rate && $rate->recurring_amount !== null ? number_format((float) $rate->recurring_amount, 0, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                @if ($rate && $rate->limited_count_amount !== null)
                                    {{ number_format((float) $rate->limited_count_amount, 0, ',', '.') }}
                                    <span class="text-xs text-gray-400">× {{ $rate->limited_count_times }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                {{ $rate && $rate->titip_amount !== null ? number_format((float) $rate->titip_amount, 0, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                @if ($rate)
                                    <span class="px-2 py-0.5 rounded-full text-xs {{ $rate->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $rate->is_active ? __('Aktif') : __('Nonaktif') }}
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800">{{ __('Belum diatur') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $package->id }})" class="text-primary hover:underline">
                                        {{ $rate ? __('Edit Rate') : __('Atur Rate') }}
                                    </button>
                                    @if ($rate)
                                        <button wire:click="deleteRate({{ $package->id }})" wire:confirm="{{ __('Hapus rate komisi paket ini?') }}" class="text-red-600 hover:underline">
                                            {{ __('Hapus') }}
                                        </button>
                                    @endif
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada Profil PPP. Buat dulu lewat menu Profil Paket → Profil PPP, lalu rate komisinya bisa diatur di sini.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $packages->links() }}
    </div>
</div>
