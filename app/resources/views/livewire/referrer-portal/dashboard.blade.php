@php
    use App\Enums\CommissionStatus;

    $statusBadge = function (CommissionStatus $status): array {
        return match ($status) {
            CommissionStatus::Pending => ['bg-gray-100 text-gray-700', 'Menunggu'],
            CommissionStatus::Eligible => ['bg-blue-100 text-blue-800', 'Layak Dibayar'],
            CommissionStatus::Approved => ['bg-amber-100 text-amber-800', 'Disetujui'],
            CommissionStatus::Paid => ['bg-green-100 text-green-800', 'Dibayar'],
            CommissionStatus::Rejected => ['bg-red-100 text-red-800', 'Ditolak'],
        };
    };
@endphp

<div class="space-y-6">
    {{-- ---------- Profil Saya ---------- --}}
    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Profil Saya') }}</h2>

        @if ($nameUpdated)
            <p class="mb-3 text-sm text-green-600">{{ __('Nama berhasil diperbarui.') }}</p>
        @endif

        <form wire:submit="updateName" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full max-w-sm rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }}</label>
                <input type="text" value="{{ $phone }}" disabled
                    class="mt-1 block w-full max-w-sm rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm">
                <p class="text-xs text-gray-500 mt-1">{{ __('Nomor HP adalah kredensial login & tujuan kode OTP, tidak bisa diubah sendiri — hubungi admin kalau perlu diganti.') }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Tipe') }}</label>
                <input type="text" value="{{ $typeLabel }}" disabled
                    class="mt-1 block w-full max-w-sm rounded-md border-gray-300 bg-gray-100 text-gray-500 shadow-sm">
            </div>

            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
                {{ __('Simpan Nama') }}
            </button>
        </form>
    </div>

    {{-- ---------- Daftar Pelanggan (SEMUA) + Catat Titip ---------- --}}
    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Daftar Pelanggan') }}</h2>
        <p class="text-xs text-gray-500 mb-3">
            {{ __('Titip pembayaran cash bisa Anda catat untuk pelanggan mana pun yang punya paket & rate Titip aktif — tidak harus pelanggan yang Anda referensikan.') }}
        </p>

        @if ($titipSuccessMessage)
            <p class="mb-3 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">
                {{ $titipSuccessMessage }}
            </p>
        @endif

        @if ($titipErrorMessage)
            <p class="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                {{ $titipErrorMessage }}
            </p>
        @endif

        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama / CID / nomor HP...') }}"
            class="mb-3 block w-full max-w-sm rounded-md border-gray-300 shadow-sm text-sm">

        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('CID') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Alamat') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Paket') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Referensi') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Titip') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($customers as $customer)
                        @php $avail = $titipAvailability[$customer->id]; @endphp
                        <tr wire:key="cust-{{ $customer->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $customer->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 font-mono">{{ $customer->cid ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $customer->address ?: '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $customer->pppPackage?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-500">{{ $customer->referredBy?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-right">
                                @if ($avail['available'])
                                    <button type="button" wire:click="startTitip({{ $customer->id }})"
                                        class="px-3 py-1 text-xs font-medium bg-primary text-white rounded-md hover:opacity-90">
                                        {{ __('Catat Titip') }}
                                    </button>
                                @else
                                    <span class="text-xs text-gray-400" title="{{ $avail['reason'] }}">
                                        {{ __('Belum tersedia') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                                {{ __('Tidak ada pelanggan.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $customers->links() }}</div>
    </div>

    {{-- ---------- Modal Alur Catat Titip ---------- --}}
    @if ($titipStage !== '' && $confirmCustomer)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
            wire:click.self="cancelTitip">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5 space-y-4">

                @if ($titipStage === 'confirm')
                    <h3 class="text-base font-semibold text-gray-800">{{ __('Konfirmasi Pencatatan Titip') }}</h3>

                    <div class="text-sm text-gray-700 space-y-1 bg-gray-50 border border-gray-200 rounded-md p-3">
                        <p><span class="text-gray-500">{{ __('Pelanggan') }}:</span> <span class="font-medium">{{ $confirmCustomer->name }}</span></p>
                        <p><span class="text-gray-500">{{ __('CID') }}:</span> <span class="font-mono">{{ $confirmCustomer->cid ?? '—' }}</span></p>
                        <p><span class="text-gray-500">{{ __('Alamat') }}:</span> {{ $confirmCustomer->address ?: '—' }}</p>
                        <p><span class="text-gray-500">{{ __('Paket') }}:</span> {{ $confirmCustomer->pppPackage?->name ?: '—' }}</p>
                        <p><span class="text-gray-500">{{ __('Komisi Titip') }}:</span>
                            <span class="font-medium">Rp {{ number_format((float) $confirmAmount, 0, ',', '.') }}</span>
                        </p>
                    </div>

                    <p class="text-sm text-gray-600">
                        {{ __('Anda menyatakan telah menerima pembayaran cash "titip" dari pelanggan ini untuk perpanjangan bulan berjalan. Admin akan memproses perpanjangan layanan secara manual.') }}
                    </p>

                    @if ($titipDuplicateWarning)
                        <div class="text-sm bg-amber-50 border border-amber-200 rounded-md p-3 space-y-2">
                            <p class="text-amber-800">
                                {{ __('Sudah ada catatan Titip untuk pelanggan ini di bulan ini (mungkin dicatat orang lain).') }}
                            </p>
                            <label class="flex items-start gap-2 text-amber-900">
                                <input type="checkbox" wire:model="titipDuplicateAcknowledged" class="mt-0.5 rounded border-gray-300">
                                <span>{{ __('Saya tetap ingin mencatat titip untuk pelanggan ini bulan ini.') }}</span>
                            </label>
                            @error('titipDuplicateAcknowledged') <p class="text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <p class="text-xs text-gray-500">
                        {{ __('Kode verifikasi 6 digit akan dikirim ke WhatsApp Anda') }} ({{ $phone }}).
                    </p>

                    @error('otpCode') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelTitip"
                            class="px-3 py-2 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>
                        <button type="button" wire:click="sendTitipOtp" wire:loading.attr="disabled"
                            class="px-4 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                            {{ __('Kirim Kode OTP') }}
                        </button>
                    </div>
                @endif

                @if ($titipStage === 'otp')
                    <h3 class="text-base font-semibold text-gray-800">{{ __('Masukkan Kode OTP') }}</h3>

                    <p class="text-sm text-gray-600">
                        {{ __('Kami mengirim kode 6 digit ke WhatsApp Anda') }} ({{ $phone }}).
                        {{ __('Kode berlaku 5 menit.') }}
                    </p>

                    @if ($otpResent)
                        <p class="text-sm text-green-600">{{ __('Kode baru telah dikirim ulang.') }}</p>
                    @endif

                    <div>
                        <input type="text" inputmode="numeric" maxlength="6" wire:model="otpCode"
                            placeholder="______"
                            class="block w-40 text-center tracking-[0.5em] text-lg rounded-md border-gray-300 shadow-sm">
                        @error('otpCode') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between">
                        <button type="button" wire:click="resendTitipOtp" wire:loading.attr="disabled"
                            class="text-sm text-primary hover:underline disabled:opacity-50">{{ __('Kirim ulang kode') }}</button>

                        <div class="flex gap-2">
                            <button type="button" wire:click="cancelTitip"
                                class="px-3 py-2 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>
                            <button type="button" wire:click="submitTitip" wire:loading.attr="disabled"
                                class="px-4 py-2 text-sm bg-primary text-white rounded-md hover:opacity-90 disabled:opacity-50">
                                {{ __('Verifikasi & Catat') }}
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ---------- Rekap Komisi (referral resmi, scheme != titip) ---------- --}}
    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Rekap Komisi') }}</h2>
        <p class="text-xs text-gray-500 mb-3">{{ __('Komisi dari pelanggan yang Anda referensikan (Per Bulan / X-Kali).') }}</p>

        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Skema') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Nominal') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Dicatat') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($commissionEntries as $entry)
                        @php [$badgeClass, $badgeLabel] = $statusBadge($entry->status); @endphp
                        <tr wire:key="commission-{{ $entry->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $entry->scheme?->label() ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-800">
                                {{ $entry->amount !== null ? 'Rp '.number_format((float) $entry->amount, 0, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-500">{{ $entry->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                                {{ __('Belum ada komisi referral tercatat.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ---------- Rekap Titip (scheme = titip, milik Referrer ini) ---------- --}}
    <div class="p-4 bg-white border border-gray-200 rounded-md">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('Rekap Titip') }}</h2>
        <p class="text-xs text-gray-500 mb-3">{{ __('Titip pembayaran cash yang Anda catat (pelanggan mana pun).') }}</p>

        <div class="overflow-x-auto border border-gray-200 rounded-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Nominal') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Dicatat') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($titipEntries as $entry)
                        @php [$badgeClass, $badgeLabel] = $statusBadge($entry->status); @endphp
                        <tr wire:key="titip-{{ $entry->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-800">
                                {{ $entry->amount !== null ? 'Rp '.number_format((float) $entry->amount, 0, ',', '.') : '—' }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-500">{{ $entry->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                {{ __('Belum ada titip tercatat.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
