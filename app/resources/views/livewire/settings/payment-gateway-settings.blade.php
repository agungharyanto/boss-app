<div class="p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-semibold mb-2" style="color: var(--color-text)">Pengaturan Payment Gateway</h1>
    <p class="text-sm text-gray-500 mb-6">
        Kredensial Xendit dan channel pembayaran yang aktif. Sandbox only — lihat CLAUDE.md.
    </p>

    @if (session('status'))
        <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-8">
        <div class="space-y-4 p-4 rounded-md border border-gray-200">
            <h2 class="text-lg font-medium text-gray-800">Kredensial Xendit</h2>

            @if ($isConfigured)
                <p class="text-sm text-gray-500">
                    Status: <span class="font-medium text-green-600">Terkonfigurasi</span>
                    (diubah terakhir: {{ $lastUpdatedAt }})
                </p>
            @else
                <p class="text-sm text-amber-600">Belum dikonfigurasi.</p>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">API Secret</label>
                <input
                    type="password"
                    wire:model="xenditSecretKey"
                    placeholder="{{ $isConfigured ? '•••••••• (tersimpan, diubah: '.$lastUpdatedAt.')' : 'Masukkan API Secret sandbox' }}"
                    autocomplete="off"
                    class="block w-full rounded-md border-gray-300 shadow-sm"
                >
                <p class="mt-1 text-xs text-gray-400">Kosongkan untuk tidak mengubah nilai yang sudah tersimpan.</p>
                @error('xenditSecretKey') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Webhook Token</label>
                <input
                    type="password"
                    wire:model="xenditWebhookToken"
                    placeholder="{{ $isConfigured ? '•••••••• (tersimpan, diubah: '.$lastUpdatedAt.')' : 'Masukkan Webhook Verification Token' }}"
                    autocomplete="off"
                    class="block w-full rounded-md border-gray-300 shadow-sm"
                >
                <p class="mt-1 text-xs text-gray-400">Kosongkan untuk tidak mengubah nilai yang sudah tersimpan.</p>
                @error('xenditWebhookToken') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="space-y-4 p-4 rounded-md border border-gray-200">
            <h2 class="text-lg font-medium text-gray-800">Channel Pembayaran</h2>
            @error('enabledChannelCodes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            @foreach ($channelsByCategory as $category => $channels)
                <div class="border-t border-gray-100 pt-3 first:border-t-0 first:pt-0">
                    <p class="text-sm font-semibold text-gray-600 mb-2">
                        {{ $channels->first()->category->label() }}
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($channels as $channel)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="enabledChannelCodes" value="{{ $channel->code }}" class="rounded border-gray-300">
                                {{ $channel->label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            Simpan Perubahan
        </button>
    </form>
</div>
