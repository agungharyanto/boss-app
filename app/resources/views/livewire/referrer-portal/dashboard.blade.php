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

    <div class="p-4 bg-blue-50 border border-blue-200 rounded-md text-sm text-blue-800">
        {{ __('Untuk mencatat perpanjangan / titip pembayaran pelanggan, buka') }}
        <a href="{{ route('web.customers.index') }}" class="font-medium underline">{{ __('Daftar Pelanggan') }}</a>
        {{ __('lalu gunakan tombol Perpanjang.') }}
    </div>

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
