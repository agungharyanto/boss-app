@php
    use App\Enums\CommissionScheme;
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
@endphp

<div class="p-6 max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-2">{{ __('Riwayat Pembayaran Komisi') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Semua komisi Referrer yang SUDAH DIBAYAR (Titip & Bulanan) — kapan, ke siapa, berapa. Read-only. Pembayaran dilakukan di halaman "Fee Komisi" atau "Payout Bulanan".') }}
    </p>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Titip Dibayar') }}</p>
            <p class="mt-1 text-xl font-semibold text-blue-700">{{ $rupiah($totalTitip) }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Bulanan Dibayar') }}</p>
            <p class="mt-1 text-xl font-semibold text-indigo-700">{{ $rupiah($totalBulanan) }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Semua') }}</p>
            <p class="mt-1 text-xl font-semibold text-gray-800">{{ $rupiah($totalAll) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $countAll }} {{ __('pembayaran') }}</p>
        </div>
    </div>

    {{-- Filter --}}
    <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-3 mb-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Dari') }}</label>
            <input type="date" wire:model.live="from" class="rounded-md border-gray-300 shadow-sm text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Sampai') }}</label>
            <input type="date" wire:model.live="to" class="rounded-md border-gray-300 shadow-sm text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Referrer') }}</label>
            <select wire:model.live="referrerId" class="rounded-md border-gray-300 shadow-sm text-sm">
                <option value="">{{ __('Semua Referrer') }}</option>
                @foreach ($referrers as $ref)
                    <option value="{{ $ref->id }}">{{ $ref->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Jenis') }}</label>
            <select wire:model.live="schemeFilter" class="rounded-md border-gray-300 shadow-sm text-sm">
                <option value="">{{ __('Semua') }}</option>
                <option value="titip">{{ __('Titip') }}</option>
                <option value="bulanan">{{ __('Bulanan') }}</option>
            </select>
        </div>
    </div>

    {{-- Grafik: total komisi dibayar per bulan (Titip vs Bulanan) --}}
    <div class="p-4 bg-white border border-gray-200 rounded-md mb-6" wire:ignore
        x-data="commissionPaidChart(@js($chartSeries))"
        x-on:commission-paid-series-updated.window="update($event.detail)">
        <p class="text-xs text-gray-500 uppercase mb-2">{{ __('Komisi Dibayar per Bulan') }}</p>
        <div class="relative" style="height: 260px">
            <canvas x-ref="canvas"></canvas>
        </div>
    </div>

    {{-- Tabel --}}
    <div class="bg-white border border-gray-200 rounded-md overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Dibayar') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Referrer') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jenis') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode / Invoice') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Jumlah') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Oleh') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Bukti') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr wire:key="rph-{{ $row->id }}">
                        <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $row->paid_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-800">{{ $row->referrer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $row->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'inline-block px-2 py-0.5 text-xs font-medium rounded',
                                'bg-blue-50 text-blue-700' => $row->scheme === CommissionScheme::Titip,
                                'bg-indigo-50 text-indigo-700' => $row->scheme !== CommissionScheme::Titip,
                            ])>{{ $row->scheme?->label() ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-600">
                            @if ($row->scheme === CommissionScheme::Titip)
                                {{ $row->payment_period?->translatedFormat('F Y') ?? '—' }}
                            @else
                                <span class="font-mono text-xs">{{ $row->invoice?->invoice_number ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right text-gray-800">{{ $rupiah($row->amount) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $row->paidBy?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($row->payment_proof_path)
                                <a href="{{ route('web.commission-payment-proofs.show', $row->id) }}" target="_blank" class="text-xs text-blue-600 hover:underline">{{ __('Lihat') }}</a>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">{{ __('Belum ada pembayaran komisi pada rentang ini.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</div>
