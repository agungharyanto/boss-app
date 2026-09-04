<div>
    <h2 class="text-base font-semibold text-gray-800 mb-1">{{ __('Lupa Password') }}</h2>

    @if ($notice)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $notice }}</p>
    @endif

    @if ($stage === 'phone')
        <p class="text-sm text-gray-500 mb-4">{{ __('Masukkan Nomor HP akun Referrer Anda. Kode verifikasi akan dikirim ke WhatsApp nomor tersebut.') }}</p>

        <form wire:submit="submitPhone" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }}</label>
                <input type="text" wire:model="phone" required autofocus
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled"
                class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                {{ __('Kirim Kode') }}
            </button>
        </form>
    @endif

    @if ($stage === 'otp')
        <form wire:submit="submitOtp" class="space-y-4">
            @if ($otpResent)
                <p class="text-sm text-green-600">{{ __('Kode baru telah dikirim ulang.') }}</p>
            @endif
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Kode Verifikasi') }}</label>
                <input type="text" inputmode="numeric" maxlength="6" wire:model="otp" required autofocus
                    placeholder="______"
                    class="mt-1 block w-40 text-center tracking-[0.5em] text-lg rounded-md border-gray-300 shadow-sm">
                @error('otp') <span class="block text-sm text-red-600 mt-1">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center justify-between">
                <button type="button" wire:click="resendOtp" wire:loading.attr="disabled"
                    class="text-sm text-blue-600 hover:underline disabled:opacity-50">{{ __('Kirim ulang kode') }}</button>
                <button type="submit" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                    {{ __('Verifikasi') }}
                </button>
            </div>
        </form>
    @endif

    @if ($stage === 'password')
        <p class="text-sm text-gray-500 mb-4">{{ __('Kode terverifikasi. Buat password baru.') }}</p>

        <form wire:submit="submitPassword" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Password Baru') }}</label>
                <input type="password" wire:model="password" required autofocus
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
                {{ __('Simpan Password Baru') }}
            </button>
        </form>
    @endif

    @if ($stage === 'done')
        <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2 mb-4">
            {{ __('Password berhasil diubah. Silakan login dengan password baru Anda.') }}
        </p>
    @endif

    <div class="mt-4 text-sm">
        <a href="{{ route('referrer.login') }}" class="text-blue-600 hover:underline">{{ __('Kembali ke halaman login') }}</a>
    </div>
</div>
