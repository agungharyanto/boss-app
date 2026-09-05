@php
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
@endphp

<div class="p-6 max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-2">{{ __('Payout Komisi Bulanan') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Komisi Per Bulan & X-Kali (dari referral resmi) berstatus "Layak Dibayar" yang belum dibayar, dikelompokkan per Referrer. Berbeda dari komisi Titip (bisa dibayar instan kapan saja) — jendela tanggal payout diatur PER PAKET (lihat Rate Komisi). Paket tanpa jendela diatur bisa dibayar kapan saja; baris dari paket berjendela tertutup dilewati otomatis saat "Proses Payout".') }}
    </p>

    @if ($flash)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $flash }}</p>
    @endif

    <div class="space-y-3">
        @forelse ($groups as $group)
            <div wire:key="mp-grp-{{ $group['referrer']?->id ?? 'none' }}"
                x-data="{ open: false }"
                class="border border-gray-200 rounded-md bg-white">

                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <button type="button" x-on:click="open = !open" class="flex items-center gap-2 text-left">
                        <span class="text-gray-400" x-text="open ? '▾' : '▸'"></span>
                        <span>
                            <span class="font-medium text-gray-800">{{ $group['referrer']?->name ?? '—' }}</span>
                            @if ($group['referrer']?->phone)
                                <span class="block text-xs text-gray-400">{{ $group['referrer']->phone }}</span>
                            @endif
                        </span>
                    </button>

                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <span class="text-gray-500">{{ $group['tx_count'] }} {{ __('baris') }}</span>
                        <span class="font-semibold text-blue-700">{{ $rupiah($group['total']) }}</span>
                        <span class="text-xs text-gray-500">
                            {{ __(':count bisa dibayar sekarang', ['count' => $group['payable_count']]) }}
                            ({{ $rupiah($group['payable_total']) }})
                        </span>

                        @if ($canManage && $group['referrer'])
                            <button type="button"
                                wire:click="payReferrer({{ $group['referrer']->id }})"
                                wire:confirm="{{ __('Proses payout komisi bulanan yang bisa dibayar sekarang untuk Referrer ini?') }}"
                                @disabled($group['payable_count'] === 0)
                                class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                                {{ __('Proses Payout') }} ({{ $group['payable_count'] }})
                            </button>
                        @endif
                    </div>
                </div>

                <div x-show="open" x-cloak class="border-t border-gray-100 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Skema') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jendela Payout') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($group['rows'] as $item)
                                @php
                                    $entry = $item['row'];
                                    $rate = $entry->customer?->pppPackage?->commissionRate;
                                @endphp
                                <tr wire:key="mp-row-{{ $entry->id }}">
                                    <td class="px-4 py-2 text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $entry->scheme?->label() }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right text-gray-800">{{ $rupiah($entry->amount) }}</td>
                                    <td class="px-4 py-2">
                                        @if ($rate && $rate->hasPayoutWindow())
                                            <span class="text-xs text-gray-600">
                                                {{ __('Tgl :start-:end', ['start' => $rate->payout_window_start_day, 'end' => $rate->payout_window_end_day]) }}
                                            </span>
                                        @else
                                            <span class="text-xs text-gray-400">{{ __('Kapan saja') }}</span>
                                        @endif
                                        @if ($item['payable_now'])
                                            <span class="inline-block ml-1 px-1.5 py-0.5 text-xs font-medium rounded bg-green-100 text-green-800">{{ __('Buka') }}</span>
                                        @else
                                            <span class="inline-block ml-1 px-1.5 py-0.5 text-xs font-medium rounded bg-gray-100 text-gray-500">{{ __('Tutup') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm text-gray-500 border border-gray-200 rounded-md bg-white">
                {{ __('Tidak ada komisi bulanan yang menunggu dibayar saat ini.') }}
            </div>
        @endforelse
    </div>
</div>
