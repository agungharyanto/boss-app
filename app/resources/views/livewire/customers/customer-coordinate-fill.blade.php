{{--
    v0.16.0 Langkah 12 — "Lengkapi Koordinat Pelanggan". List of
    customers with no coordinates; a per-row "Set Lokasi" opens the
    shared Leaflet picker (partials/location-map, same as FiberNodeForm /
    OdpEdit). Saving writes ONLY customers.latitude/longitude — no ODP
    link. Once filled, the customer disappears from this list and shows
    up on the Peta Topologi "Pelanggan" layer automatically.
--}}
<div class="p-6 max-w-5xl mx-auto space-y-4"
     x-data="{ geoBusy: false, locate() {
        if (!navigator.geolocation) { alert('Perangkat tidak mendukung lokasi.'); return; }
        this.geoBusy = true;
        navigator.geolocation.getCurrentPosition(
            (p) => { $wire.set('latitude', p.coords.latitude.toFixed(7)); $wire.set('longitude', p.coords.longitude.toFixed(7)); this.geoBusy = false; },
            () => { this.geoBusy = false; alert('Gagal mengambil lokasi.'); },
            { enableHighAccuracy: true, timeout: 10000 },
        );
     } }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Lengkapi Koordinat Pelanggan') }}</h1>
        <a href="{{ route('web.fiber-topology-map.index') }}" class="text-sm text-primary hover:underline">{{ __('Peta Topologi') }} &rarr;</a>
    </div>

    <p class="text-sm text-gray-500">{{ __('Isi titik lokasi pelanggan yang belum punya koordinat. Ini hanya mengisi koordinat pelanggan — tidak membuat tautan ke ODP mana pun.') }}</p>

    @if (session('coordinate-status'))
        <div class="p-2 bg-green-50 border border-green-200 text-green-800 text-xs rounded-md">{{ session('coordinate-status') }}</div>
    @endif

    <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Cari nama / kode / nomor HP…') }}"
           class="block w-full max-w-sm rounded-md border-gray-300 shadow-sm text-sm">

    {{-- Picker (shown while editing one customer) --}}
    @if ($editingCustomerId)
        @php $editing = $customers->firstWhere('id', $editingCustomerId) ?? \App\Models\Customer::find($editingCustomerId); @endphp
        <div class="border border-gray-200 rounded-md p-4 space-y-3 bg-gray-50">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-700">{{ __('Set Lokasi') }}: {{ $editing?->name }}</h2>
                <button type="button" wire:click="cancelEditing" class="text-xs text-gray-500 hover:underline">{{ __('Batal') }}</button>
            </div>

            <div class="grid grid-cols-2 gap-2 max-w-md">
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('Latitude') }}</label>
                    <input type="text" wire:model="latitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('latitude') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">{{ __('Longitude') }}</label>
                    <input type="text" wire:model="longitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @error('longitude') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <button type="button" x-on:click="locate()" x-bind:disabled="geoBusy" class="text-xs px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">
                <span x-show="!geoBusy">{{ __('Ambil lokasi saya') }}</span>
                <span x-show="geoBusy" x-cloak>{{ __('Mengambil…') }}</span>
            </button>

            @include('livewire.network.partials.location-map', ['mapPoints' => []])

            <button type="button" wire:click="saveLocation" wire:loading.attr="disabled" wire:target="saveLocation"
                    class="px-4 py-2 bg-primary text-white text-sm rounded-md hover:opacity-90 disabled:opacity-50">
                <span wire:loading.remove wire:target="saveLocation">{{ __('Simpan Koordinat') }}</span>
                <span wire:loading wire:target="saveLocation">{{ __('Menyimpan…') }}</span>
            </button>
        </div>
    @endif

    <div class="border border-gray-200 rounded-md overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Kode') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600">{{ __('Alamat') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600 w-28"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($customers as $customer)
                    <tr @class(['bg-primary/5' => $customer->id === $editingCustomerId])>
                        <td class="px-4 py-2 text-gray-800">{{ $customer->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $customer->cid }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ \Illuminate\Support\Str::limit($customer->address, 60) }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($canManage)
                                <button type="button" wire:click="startEditing({{ $customer->id }})"
                                        class="text-xs px-2 py-1 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Set Lokasi') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">{{ __('Semua pelanggan yang cocok sudah punya koordinat.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $customers->links() }}</div>
</div>
