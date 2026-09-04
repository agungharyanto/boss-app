<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Data Pelanggan') }}</h1>

        @unless ($referrerView)
            <div class="flex gap-2">
                @if ($canRegister)
                    <a href="{{ route('web.customers.register') }}" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        Registrasi Pelanggan
                    </a>
                @endif

                @if ($canCreate)
                    <button
                        wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
                    >
                        {{ $showCreateForm ? 'Batal' : __('+ Pelanggan Baru') }}
                    </button>
                @endif
            </div>
        @endunless
    </div>

    @if ($referrerView)
        <p class="mb-4 text-sm text-gray-500">
            {{ __('Anda masuk sebagai Referral. Gunakan tombol Perpanjang untuk mencatat perpanjangan langganan pelanggan.') }}
        </p>
    @endif

    @if ($renewFlash)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $renewFlash }}</p>
    @endif
    @if ($renewError)
        <p class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{{ $renewError }}</p>
    @endif

    @if (! $referrerView && $showCreateForm)
        <form wire:submit="createCustomer" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">Nama</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Alamat</label>
                <textarea wire:model="address" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                @error('address') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Nomor Telepon Utama</label>
                <input type="text" wire:model="phone_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone_number') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                Simpan
            </button>
        </form>
    @endif

    <div class="flex gap-3 mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama, CID, atau nomor telepon...') }}"
            class="flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua Status') }}</option>
            @foreach (\App\Enums\CustomerStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">CID</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nama</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Telepon</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <td class="px-4 py-2 font-mono text-sm">{{ $customer->cid ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $customer->name }}</td>
                        <td class="px-4 py-2">{{ $customer->phone_number }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100">{{ $customer->status->label() }}</span>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="openRenew({{ $customer->id }})"
                                title="{{ __('Perpanjang Langganan') }}"
                                class="inline-flex items-center gap-1 text-amber-600 hover:underline">
                                <span aria-hidden="true">⚡</span> {{ __('Perpanjang') }}
                            </button>
                            @unless ($referrerView)
                                <a href="{{ route('web.customers.show', $customer) }}" class="ml-3 text-primary hover:underline">
                                    {{ __('Detail') }}
                                </a>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">{{ __('Belum ada data pelanggan.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $customers->links() }}
    </div>

    {{-- ---------- Modal Perpanjang Langganan ---------- --}}
    @if ($renewModalOpen && $renewCustomer)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50" wire:click.self="closeRenew">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4">
                <h3 class="text-base font-semibold text-gray-800">{{ __('Perpanjang Langganan') }}</h3>

                <div class="text-sm text-gray-700 space-y-1 bg-gray-50 border border-gray-200 rounded-md p-3">
                    <p><span class="text-gray-500">{{ __('Pelanggan') }}:</span> <span class="font-medium">{{ $renewCustomer->name }}</span></p>
                    <p><span class="text-gray-500">{{ __('CID') }}:</span> <span class="font-mono">{{ $renewCustomer->cid ?? '—' }}</span></p>
                    <p><span class="text-gray-500">{{ __('Paket saat ini') }}:</span> {{ $renewCustomer->pppPackage?->name ?? '—' }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Ubah Paket (Opsional)') }}</label>
                    <select wire:model="renewNewPackageId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        <option value="">{{ __('— Tidak ganti paket —') }}</option>
                        @foreach ($packages as $pkg)
                            <option value="{{ $pkg->id }}" @disabled($pkg->id === $renewCustomer->ppp_package_id)>
                                {{ $pkg->name }}{{ $pkg->id === $renewCustomer->ppp_package_id ? ' ('.__('paket saat ini').')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">{{ __('Kosongkan kalau paket tidak berubah. Hanya mengubah data BOSS App — tidak menyentuh router/RADIUS.') }}</p>
                </div>

                @if ($actingReferrer)
                    {{-- OTP wajib untuk acting Referrer --}}
                    <div class="border-t border-gray-100 pt-3 space-y-3">
                        @if (! $renewOtpSent)
                            <p class="text-xs text-gray-500">
                                {{ __('Kode verifikasi 6 digit akan dikirim ke WhatsApp Anda') }} ({{ $actingReferrer->phone }}).
                            </p>
                            <button type="button" wire:click="sendRenewOtp" wire:loading.attr="disabled"
                                class="px-3 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                                {{ __('Kirim Kode Verifikasi') }}
                            </button>
                        @else
                            <p class="text-xs text-gray-500">{{ __('Kode 6 digit dikirim ke WhatsApp Anda. Berlaku 5 menit.') }}</p>
                            @if ($renewOtpResent)
                                <p class="text-xs text-green-600">{{ __('Kode baru telah dikirim ulang.') }}</p>
                            @endif
                            <div class="flex items-center gap-2">
                                <input type="text" inputmode="numeric" maxlength="6" wire:model="renewOtp"
                                    placeholder="______" @disabled($renewOtpVerified)
                                    class="w-32 text-center tracking-[0.4em] rounded-md border-gray-300 shadow-sm disabled:bg-gray-100">
                                @if ($renewOtpVerified)
                                    <span class="text-sm text-green-700">✓ {{ __('Terverifikasi') }}</span>
                                @else
                                    <button type="button" wire:click="verifyRenewOtp" wire:loading.attr="disabled"
                                        class="px-3 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">
                                        {{ __('Verifikasi') }}
                                    </button>
                                    <button type="button" wire:click="resendRenewOtp" wire:loading.attr="disabled"
                                        class="text-xs text-primary hover:underline disabled:opacity-50">{{ __('Kirim ulang') }}</button>
                                @endif
                            </div>
                        @endif
                        @error('renewOtp') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="border-t border-gray-100 pt-3">
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                            {{ __('Akun Anda tidak tertaut ke Referral — verifikasi OTP tidak berlaku. Perpanjangan dicatat atas otoritas admin Anda, tanpa komisi.') }}
                        </p>
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="closeRenew"
                        class="px-3 py-2 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>
                    <button type="button" wire:click="submitRenew" wire:loading.attr="disabled"
                        @disabled($actingReferrer && ! $renewOtpVerified)
                        class="px-4 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ __('Perpanjang') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
