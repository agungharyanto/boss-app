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

<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Titip Masuk') }}</h1>
    </div>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Pembayaran cash "titip" yang dicatat Referrer lewat portal (terverifikasi OTP WhatsApp). Ini daftar kerja: perpanjang layanan pelanggan berikut secara manual di MixRadius. Tidak ada tombol approve — nominal sudah dikunci ke rate komisi.') }}
    </p>

    <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan / referrer...') }}"
            class="flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua status') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Referrer') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Nominal Komisi') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Dicatat') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($entries as $entry)
                    @php [$badgeClass, $badgeLabel] = $statusBadge($entry->status); @endphp
                    <tr wire:key="titip-{{ $entry->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $entry->referrer?->name ?? '—' }}
                            @if ($entry->referrer?->phone)
                                <span class="block text-xs text-gray-400">{{ $entry->referrer->phone }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm text-right text-gray-800">
                            {{ $entry->amount !== null ? 'Rp '.number_format((float) $entry->amount, 0, ',', '.') : '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ $entry->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada titip masuk.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
