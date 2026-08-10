<div class="p-6 max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">{{ __('Work Order') }} #{{ $work_order->id }}</h1>
            <p class="text-sm text-gray-500">{{ $work_order->customer?->name ?? '—' }}</p>
        </div>
        <span class="px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-700">
            {{ str_replace('_', ' ', $work_order->status->value) }}
        </span>
    </div>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    <p class="mb-6 text-xs text-gray-500 bg-yellow-50 border border-yellow-100 rounded-md p-3">
        {{ __('Jalur input sementara — CS/admin mengisi manual dari info yang direlai teknisi lewat telepon/WA pribadi. Ini bukan form self-service teknisi lapangan (itu masih backlog terpisah).') }}
    </p>

    <div class="border border-gray-200 rounded-md overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jenis') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Serial Number') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('SSID') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Password') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Provisioning') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($devices as $device)
                    <tr wire:key="wo-device-{{ $device->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $device->device_type->label() }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600 font-mono">{{ $device->serial_number }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $device->ssid ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $device->wifi_password !== null ? __('(tersimpan)') : '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            @if ($device->cpeDevice?->wifi_provisioned_at)
                                <span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">
                                    {{ __('Terkirim') }} {{ $device->cpeDevice->wifi_provisioned_at->diffForHumans() }}
                                </span>
                            @elseif ($device->cpeDevice === null)
                                <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">{{ __('Belum di-bind') }}</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-700">{{ __('Menunggu') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm">
                            @if ($canManage)
                                <button wire:click="openProvisioningForm({{ $device->id }})" class="text-primary hover:underline">
                                    {{ __('Isi WiFi') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada perangkat tercatat di work order ini.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- PROVISIONING MODAL — partial update sungguhan: kosongkan salah satu
         field artinya "tidak diubah sekarang", bukan "hapus". --}}
    @if ($provisioningDeviceId !== null)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeProvisioningForm">
            <div class="bg-white rounded-md p-6 w-full max-w-md space-y-4">
                <h2 class="font-medium">{{ __('Isi Kredensial WiFi') }}</h2>
                <p class="text-xs text-gray-500">
                    {{ __('Kosongkan field yang belum diketahui — tidak akan menghapus nilai yang sudah tersimpan.') }}
                </p>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('SSID') }}</label>
                    <input type="text" wire:model="ssid" maxlength="32" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('ssid') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Password WiFi') }}</label>
                    <input type="password" wire:model="wifiPassword" maxlength="63" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">{{ __('8-63 karakter (standar WPA-PSK).') }}</p>
                    @error('wifiPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button wire:click="saveProvisioning" wire:loading.attr="disabled" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                        {{ __('Simpan') }}
                    </button>
                    <button wire:click="closeProvisioningForm" type="button" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">
                        {{ __('Batal') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
