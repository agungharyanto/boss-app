<div class="p-6 max-w-3xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Cek Status Device') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            {{ __('Diagnosa mandiri kenapa sebuah device belum muncul di Perangkat CPE — isi Serial Number dan/atau Nomor HP pelanggan.') }}
        </p>
    </div>

    <form wire:submit="check" class="mb-8 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Serial Number') }}</label>
            <input
                type="text" wire:model="serialNumber" placeholder="mis. ZTEGCB399CEB"
                class="w-full rounded-md border-gray-300 shadow-sm font-mono text-sm"
            >
            @error('serialNumber') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Nomor HP Pelanggan') }}</label>
            <input
                type="text" wire:model="phoneNumber" placeholder="mis. 085156034227"
                class="w-full rounded-md border-gray-300 shadow-sm text-sm"
            >
            @error('phoneNumber') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <p class="text-xs text-gray-500">{{ __('Isi minimal salah satu. Kalau cuma Serial Number diisi, nomor HP pelanggan akan dicoba diturunkan otomatis dari kandidat cocok di langkah 2 (kalau ada).') }}</p>

        <div class="flex items-center gap-2">
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                {{ __('Cek') }}
            </button>
            @if ($hasSearched)
                <button type="button" wire:click="reset" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm">
                    {{ __('Reset') }}
                </button>
            @endif
        </div>
    </form>

    @if ($hasSearched && $result)
        @php
            $steps = [
                ['key' => 'genieacs', 'title' => __('1. Device pernah inform ke GenieACS?')],
                ['key' => 'mac_map', 'title' => __('2. Ada di peta MAC legacy (legacy_mac_customer_map)?')],
                ['key' => 'customer', 'title' => __('3. Customer dengan HP ini ada di database BOSS App?')],
                ['key' => 'binding', 'title' => __('4. Sudah ter-bind ke cpe_devices?')],
            ];
        @endphp

        <div class="space-y-4">
            @foreach ($steps as $step)
                @php $s = $result[$step['key']]; @endphp
                <div class="p-4 border rounded-md {{ ! $s['checked'] ? 'border-gray-200 bg-gray-50' : ($s['passed'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50') }}">
                    <div class="flex items-start gap-3">
                        <span class="text-lg leading-none mt-0.5">
                            @if (! $s['checked'])
                                <span class="text-gray-400">➖</span>
                            @elseif ($s['passed'])
                                <span class="text-green-600">✅</span>
                            @else
                                <span class="text-red-600">❌</span>
                            @endif
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-800 text-sm">{{ $step['title'] }}</div>
                            <div class="text-sm text-gray-600 mt-1">{{ $s['detail'] }}</div>
                            @if ($s['suggestion'])
                                <div class="text-xs text-amber-800 bg-amber-100 border border-amber-200 rounded-md px-3 py-2 mt-2">
                                    <span class="font-medium">{{ __('Saran') }}:</span> {{ $s['suggestion'] }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
