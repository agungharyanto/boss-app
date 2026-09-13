<div class="mt-8 pt-6 border-t border-gray-200">
    <h2 class="text-lg font-semibold mb-2" style="color: var(--color-text)">{{ __('Push Konfig') }}</h2>
    <p class="text-sm text-gray-500 mb-4">
        {{ __('Cari pelanggan/device lalu kirim Push Konfig manual — tombol ini memanggil service yang SAMA PERSIS dengan "Push Konfig Sekarang" di Detail Perangkat CPE (hanya WAN1 PPPoE username/password, ke path yang sudah aktif — bukan provisioning WAN1 baru). Bagian Auto-WAN di atas TIDAK terpengaruh sama sekali.') }}
    </p>

    <div class="mb-3">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan, CID, nomor HP, atau serial number...') }}"
            class="block w-full rounded-md border-gray-300 shadow-sm text-sm"
        >
    </div>

    @if ($search !== '' && $selectedDeviceId === null)
        <div class="border border-gray-200 rounded-md divide-y divide-gray-100 mb-4">
            @forelse ($results as $device)
                <button
                    type="button"
                    wire:click="selectDevice({{ $device->id }})"
                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50"
                >
                    <span class="font-medium text-gray-800">{{ $device->customer?->name ?? '—' }}</span>
                    <span class="text-gray-400 mx-1">·</span>
                    <span class="text-gray-500">{{ $device->customer?->cid ?? '—' }}</span>
                    <span class="text-gray-400 mx-1">·</span>
                    <span class="font-mono text-xs text-gray-500">{{ $device->serial_number }}</span>
                </button>
            @empty
                <p class="px-3 py-2 text-sm text-gray-500">{{ __('Tidak ada pelanggan/device yang cocok.') }}</p>
            @endforelse
        </div>
    @endif

    @if ($selectedDevice)
        <div class="p-4 rounded-md border border-gray-200 bg-white space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-800">{{ $selectedDevice->customer?->name ?? '—' }}</p>
                    <p class="text-xs text-gray-500 font-mono">{{ $selectedDevice->serial_number }}</p>
                </div>
                <button type="button" wire:click="clearSelection" class="text-xs text-gray-400 hover:text-gray-600">
                    {{ __('Ganti pencarian') }}
                </button>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-2 text-sm">
                <dt class="text-gray-500">{{ __('Paket') }}</dt>
                <dd class="text-gray-800">{{ $selectedDevice->customer?->pppPackage?->name ?? '—' }}</dd>

                <dt class="text-gray-500">{{ __('Tipe Modem') }}</dt>
                <dd class="text-gray-800">{{ $selectedDevice->modemType?->name ?? '—' }}</dd>

                <dt class="text-gray-500">{{ __('Template Konfig CPE') }}</dt>
                <dd class="text-gray-800">
                    @if ($resolvedTemplate)
                        {{ $resolvedTemplate->name }}
                        @if (! $resolvedTemplate->wan1_enabled)
                            <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-gray-100 text-gray-600">{{ __('WAN1 nonaktif') }}</span>
                        @endif
                    @else
                        <span class="text-amber-600">{{ __('Tidak ada Template yang cocok untuk kombinasi Paket + Tipe Modem device ini.') }}</span>
                    @endif
                </dd>
            </dl>

            @if ($pushMessage)
                <p class="text-sm {{ $pushWasError ? 'text-red-600' : 'text-green-700' }}">{{ $pushMessage }}</p>
            @endif

            @if ($canManage)
                <button
                    type="button"
                    wire:click="pushNow"
                    wire:loading.attr="disabled"
                    @disabled($resolvedTemplate === null)
                    class="px-4 py-2 text-sm font-medium bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                    {{ __('Push Sekarang') }}
                </button>
            @else
                <p class="text-xs text-gray-400">{{ __('Anda tidak punya izin mengirim Push Konfig.') }}</p>
            @endif
        </div>
    @endif
</div>
