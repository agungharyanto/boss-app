<div class="p-6 max-w-2xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-1">{{ __('Konfig WA Gateway') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Pengaturan timing dispatch & reminder Work Order. Notifikasi WhatsApp sungguhan belum aktif — sub-versi ini cuma menyiapkan jadwalnya.') }}
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
            <label class="block text-sm font-medium text-gray-700">{{ __('Nama Grup WhatsApp') }} <span class="text-gray-400 font-normal">({{ __('opsional') }})</span></label>
            <input type="text" wire:model="waGroupName" placeholder="{{ __('Grup Notifikasi Teknisi') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('waGroupName') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('JID Grup WhatsApp') }}</label>
            <input type="text" disabled placeholder="{{ __('Belum tersedia — menunggu v0.26.4') }}" class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 text-gray-400 shadow-sm cursor-not-allowed">
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
            {{ __('Simpan') }}
        </button>
    </form>
</div>
