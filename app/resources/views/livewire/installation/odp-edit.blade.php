{{--
    v0.16.0 Core Network Infrastructure Management, Langkah 3. code/name
    are shown READ-ONLY on purpose — editing them stays the job of the
    existing v0.5.0 API (OdpController), never wired into this page.
--}}
<div class="p-6 max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Edit Data Topologi ODP') }}: {{ $code }}</h1>
        <a href="{{ route('web.odps.detail', $odpId) }}" class="text-sm text-primary hover:underline">{{ __('Lihat Detail Topologi') }}</a>
    </div>

    {{-- v0.16.0 Langkah 4 — Google Maps direction link, functional addition only. --}}
    @if ($latitude !== null && $longitude !== null)
        <p class="text-sm text-gray-500 mb-4">
            <a href="https://www.google.com/maps/dir/?api=1&destination={{ $latitude }},{{ $longitude }}" target="_blank" rel="noopener" class="text-primary hover:underline">
                {{ __('Buka arah di Google Maps') }} ({{ $latitude }}, {{ $longitude }})
            </a>
        </p>
    @endif

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 text-sm rounded-md">
            {{ session('status') }}
        </div>
    @endif

    <div class="p-4 border border-gray-200 rounded-md bg-gray-50 space-y-4 mb-6">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
                <span class="block text-gray-500">{{ __('Kode ODP') }}</span>
                <span class="font-medium">{{ $code }}</span>
            </div>
            <div>
                <span class="block text-gray-500">{{ __('Nama') }}</span>
                <span class="font-medium">{{ $name }}</span>
            </div>
        </div>
        <p class="text-xs text-gray-500">
            {{ __('Kode/nama/jumlah port ODP dikelola lewat alur registrasi ODP yang sudah ada, bukan di halaman ini.') }}
        </p>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Titik Induk (Parent)') }}</label>
            <select wire:model="parentId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('-- Tidak ada --') }}</option>
                @foreach ($parentOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            @error('parentId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Redaman Masuk (loss in, dB)') }}</label>
                <input type="text" wire:model="lossInDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('lossInDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Redaman Keluar (loss out, dB)') }}</label>
                <input type="text" wire:model="lossOutDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('lossOutDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <button wire:click="save" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            {{ __('Simpan') }}
        </button>
    </div>

    @livewire('network.gps-photo-capture', ['ownerType' => \App\Models\Odp::class, 'ownerId' => $odpId])
</div>
