<div>
    <h2 class="text-base font-semibold text-gray-800 mb-1">{{ __('Ganti Password') }}</h2>
    <p class="text-sm text-gray-500 mb-4">
        {{ __('Ini login pertama Anda — password sementara harus diganti sebelum melanjutkan.') }}
    </p>

    <form wire:submit="submit" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Password Saat Ini') }}</label>
            <input type="password" wire:model="currentPassword" required autofocus
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('currentPassword') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Password Baru') }}</label>
            <input type="password" wire:model="password" required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
            @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Ulangi Password Baru') }}</label>
            <input type="password" wire:model="password_confirmation" required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
        </div>
        <button type="submit" wire:loading.attr="disabled"
            class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
            {{ __('Simpan & Lanjutkan') }}
        </button>
    </form>
</div>
