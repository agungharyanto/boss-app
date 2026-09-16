<div class="p-6 max-w-2xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-1">{{ __('Konfig WA Gateway') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Pengaturan timing dispatch & reminder Work Order, dan grup WhatsApp opsional untuk broadcast notifikasinya.') }}
    </p>

    @if (session('status'))
        <div class="mb-6 p-4 rounded-md border border-green-300 bg-green-50">
            <p class="text-sm text-green-700">{{ session('status') }}</p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-5 bg-white border border-gray-200 rounded-md p-6">
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Offset Dispatch (jam)') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Berapa jam sebelum janji kunjungan pelanggan, Work Order otomatis "keluar" ke teknisi.') }}
            </p>
            <input type="number" min="1" step="1" wire:model="dispatchOffsetHours" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('dispatchOffsetHours') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Jam Reminder Harian') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Jam mulai command mengecek Work Order yang sudah dispatch tapi belum selesai, tiap hari.') }}
            </p>
            <input type="time" wire:model="reminderTime" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('reminderTime') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Interval Command (menit)') }}</label>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Seberapa sering command dispatch/reminder benar-benar jalan (1-60 menit).') }}
            </p>
            <input type="number" min="1" max="60" step="1" wire:model="commandIntervalMinutes" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('commandIntervalMinutes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label class="block text-sm font-medium text-gray-700">{{ __('Pilih Grup WhatsApp') }} <span class="text-gray-400 font-normal">({{ __('opsional') }})</span></label>
                <button type="button" wire:click="reloadGroups" wire:loading.attr="disabled" class="text-xs text-primary hover:underline">
                    {{ __('Muat Ulang Daftar Grup') }}
                </button>
            </div>
            <p class="text-xs text-gray-500 mb-1">
                {{ __('Semua grup yang nomor bot ini sudah jadi anggota — tanpa filter. Kosongkan untuk menonaktifkan broadcast ke grup.') }}
            </p>

            @if (! $sessionConnected)
                <select disabled class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 text-gray-400 shadow-sm cursor-not-allowed">
                    <option>{{ __('Sesi WhatsApp belum terhubung') }}</option>
                </select>
            @elseif ($groupsLoadFailed)
                <select disabled class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 text-gray-400 shadow-sm cursor-not-allowed">
                    <option>{{ __('Belum ada grup ditemukan, atau gagal memuat — coba Muat Ulang') }}</option>
                </select>
            @else
                <select wire:model.live="waGroupJid" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('— Tidak ada (nonaktif) —') }}</option>
                    @foreach ($groupOptions as $group)
                        <option value="{{ $group['jid'] }}">{{ $group['name'] }}</option>
                    @endforeach
                </select>
            @endif
            @error('waGroupJid') <span class="text-sm text-red-600">{{ $message }}</span> @enderror

            @if ($waGroupJid)
                <p class="text-xs text-gray-400 mt-1">{{ __('JID:') }} <span class="font-mono">{{ $waGroupJid }}</span></p>
            @endif
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            {{ __('Simpan') }}
        </button>
    </form>
</div>
