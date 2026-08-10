<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Perangkat CPE') }}</h1>
    </div>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif
    @if (session('actionError'))
        <div class="mb-4 text-sm text-red-600">{{ session('actionError') }}</div>
    @endif

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan atau nomor serial...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Manufacturer / Model') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Serial Number') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Inform') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($devices as $device)
                    <tr wire:key="cpe-device-{{ $device->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">
                            {{ $device->customer?->name ?? '—' }}
                            @if ($device->reseller)
                                <span class="block text-xs text-gray-400">{{ $device->reseller->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $device->manufacturer ?? '—' }}
                            @if ($device->model_name)
                                <span class="block text-xs text-gray-400">{{ $device->model_name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600 font-mono">{{ $device->serial_number }}</td>
                        <td class="px-4 py-2 text-sm">
                            @php
                                $statusColor = match ($device->status->value) {
                                    'online' => 'bg-green-100 text-green-700',
                                    'offline' => 'bg-red-100 text-red-700',
                                    default => 'bg-yellow-100 text-yellow-700',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $statusColor }}">
                                {{ $device->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $device->last_inform_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <button wire:click="openHistoryModal({{ $device->id }})" class="text-primary hover:underline">
                                    {{ __('Riwayat') }}
                                </button>
                                @can('manage', $device)
                                    <button
                                        wire:click="reboot({{ $device->id }})"
                                        wire:confirm="Yakin reboot perangkat ini? Pelanggan akan terputus sebentar sampai perangkat menyala kembali. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil)."
                                        wire:loading.attr="disabled"
                                        class="text-primary hover:underline"
                                    >
                                        {{ __('Reboot') }}
                                    </button>
                                    <button wire:click="openWifiModal({{ $device->id }})" class="text-primary hover:underline">
                                        {{ __('Ganti WiFi') }}
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada perangkat CPE.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $devices->links() }}
    </div>

    {{-- GANTI WIFI MODAL — SSID dan/atau password baru. Konfirmasi WAJIB
         ada di tombol submit (bukan saat modal dibuka), karena baru di
         situ ada niat nyata untuk mengubah sesuatu. --}}
    @if ($showWifiModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeWifiModal">
            <div class="bg-white rounded-md p-6 w-full max-w-md space-y-4">
                <h2 class="font-medium">{{ __('Ganti WiFi') }}</h2>
                <p class="text-xs text-gray-500">
                    {{ __('Isi salah satu atau keduanya. Perintah ini TIDAK instan — diterapkan saat perangkat terhubung berikutnya (atau langsung kalau Connection Request kebetulan berhasil).') }}
                </p>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('SSID Baru') }}</label>
                    <input type="text" wire:model="wifiSsid" maxlength="32" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    @error('wifiSsid') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('Password Baru') }}</label>
                    <input type="password" wire:model="wifiPassword" maxlength="63" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">{{ __('8-63 karakter (standar WPA-PSK).') }}</p>
                    @error('wifiPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button
                        wire:click="submitWifi"
                        wire:confirm="Yakin ganti SSID/password WiFi? Semua perangkat pelanggan yang sudah terhubung mungkin perlu connect ulang setelah perubahan diterapkan."
                        wire:loading.attr="disabled"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm"
                    >
                        {{ __('Kirim Perintah') }}
                    </button>
                    <button wire:click="closeWifiModal" type="button" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">
                        {{ __('Batal') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- RIWAYAT AKSI MODAL --}}
    @if ($showHistoryModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeHistoryModal">
            <div class="bg-white rounded-md p-6 w-full max-w-2xl space-y-4 max-h-[80vh] overflow-y-auto">
                <h2 class="font-medium">{{ __('Riwayat Aksi') }}</h2>
                <p class="text-xs text-gray-500">
                    {{ __('"Terkirim ke GenieACS" berarti perintah berhasil masuk antrean — BUKAN konfirmasi perangkat sudah menjalankannya.') }}
                </p>

                <div class="divide-y divide-gray-100">
                    @forelse ($historyLogs as $log)
                        <div class="py-3 text-sm" wire:key="action-log-{{ $log->id }}">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ $log->action_type->label() }}</span>
                                @php
                                    $logStatusColor = match ($log->status->value) {
                                        'delivered' => 'bg-blue-100 text-blue-700',
                                        'failed' => 'bg-red-100 text-red-700',
                                        default => 'bg-yellow-100 text-yellow-700',
                                    };
                                @endphp
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $logStatusColor }}">
                                    {{ $log->status->label() }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                {{ $log->created_at->diffForHumans() }} · {{ __('oleh') }} {{ $log->performedBy?->name ?? '—' }}
                            </div>
                            @if ($log->action_type->value === 'set_ssid' && isset($log->parameters['new_ssid']))
                                <div class="text-xs text-gray-600 mt-1">{{ __('SSID baru') }}: {{ $log->parameters['new_ssid'] }}</div>
                            @endif
                            @if (($log->parameters['password_changed'] ?? false))
                                <div class="text-xs text-gray-600 mt-1">{{ __('Password diubah') }}</div>
                            @endif
                            @if ($log->status->value === 'failed' && $log->failed_reason)
                                <div class="text-xs text-red-600 mt-1">{{ $log->failed_reason }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 py-4">{{ __('Belum ada aksi tercatat untuk perangkat ini.') }}</p>
                    @endforelse
                </div>

                <div class="pt-2">
                    <button wire:click="closeHistoryModal" type="button" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">
                        {{ __('Tutup') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
