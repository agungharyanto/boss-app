@php
    use App\Enums\CommissionStatus;
    use App\Enums\TitipDepositStatus;

    $statusBadge = function (CommissionStatus $status): array {
        return match ($status) {
            CommissionStatus::Pending => ['bg-gray-100 text-gray-700', 'Menunggu'],
            CommissionStatus::Eligible => ['bg-blue-100 text-blue-800', 'Layak Dibayar'],
            CommissionStatus::Approved => ['bg-amber-100 text-amber-800', 'Disetujui'],
            CommissionStatus::Paid => ['bg-green-100 text-green-800', 'Dibayar'],
            CommissionStatus::Rejected => ['bg-red-100 text-red-800', 'Ditolak'],
        };
    };
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
@endphp

<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Fee Komisi') }}</h1>
    </div>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Pembayaran cash "titip" yang dicatat lewat aksi Perpanjang (terverifikasi OTP WhatsApp). Referrer memegang uang PENUH dari pelanggan lalu menyetorkannya ke admin; komisi dibayar balik terpisah. Centang transaksi yang uangnya benar-benar sudah disetor, lalu "Tandai Sudah Setor (Terpilih)". Perpanjang layanan pelanggan secara manual di MixRadius.') }}
    </p>

    @if ($flash)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $flash }}</p>
    @endif

    {{-- ---------- Kartu ringkasan ---------- --}}
    <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 mb-3">
        {{ __('2 kartu di bawah melacak 2 arus uang yang BERBEDA dan SALING TIDAK MEMPENGARUHI — menandai sebuah transaksi "Sudah Setor" tidak mengubah angka "Total Komisi Harus Dibayar", karena itu bukan hal yang sama.') }}
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Komisi Harus Dibayar') }}</p>
            <p class="mt-1 text-2xl font-semibold text-blue-700">{{ $rupiah($totalKomisiHarusDibayar) }}</p>
            <p class="text-xs text-gray-400 mt-1">
                {{ __('Arah: perusahaan → Referrer. Uang komisi yang harus dibayar BALIK ke Referrer (status "Layak Dibayar"). Belum ada fitur payout — angka ini TIDAK berubah oleh status Setoran di kartu sebelah.') }}
            </p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Setoran Belum Masuk') }}</p>
            <p class="mt-1 text-2xl font-semibold text-orange-700">{{ $rupiah($totalSetoranBelumMasuk) }}</p>
            <p class="text-xs text-gray-400 mt-1">
                {{ __('Arah: Referrer → perusahaan. Uang CASH pelanggan yang masih dipegang Referrer, belum disetor. Independen dari kartu Komisi di atas.') }}
            </p>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
        <input
            type="text" wire:key="fk-search" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan / referrer...') }}"
            class="flex-1 rounded-md border-gray-300 shadow-sm"
        >
        {{-- wire:key wajib: dua <select> berstruktur nyaris identik & tanpa
             key gampang tertukar identitasnya saat Livewire morph DOM —
             itu penyebab filter "status komisi" tampak tidak bekerja
             padahal query-nya benar. --}}
        <select wire:key="fk-status-filter" wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua status komisi') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
        <select wire:key="fk-deposit-filter" wire:model.live="depositFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua status setoran') }}</option>
            @foreach ($depositStatuses as $ds)
                <option value="{{ $ds->value }}">{{ $ds->label() }}</option>
            @endforeach
        </select>

        @if ($canManage)
            <button type="button"
                wire:click="markSelectedDeposited"
                wire:confirm="{{ __('Tandai transaksi terpilih sebagai sudah setor?') }}"
                @disabled($selectedCount === 0)
                class="px-3 py-2 text-sm font-medium bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                {{ __('Tandai Sudah Setor (Terpilih)') }}
                @if ($selectedCount > 0) <span class="ml-1 opacity-90">({{ $selectedCount }})</span> @endif
            </button>
        @endif
    </div>

    {{-- ---------- Daftar dikelompokkan per Referrer ---------- --}}
    <div class="space-y-3">
        @forelse ($groups as $group)
            <div wire:key="grp-{{ $group['referrer']?->id ?? 'none' }}"
                x-data="{ open: false }"
                class="border border-gray-200 rounded-md bg-white">

                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-2">
                        @if ($canManage && $group['belum_setor_count'] > 0 && $group['referrer'])
                            <input type="checkbox"
                                wire:click="toggleGroupSelection({{ $group['referrer']->id }})"
                                @checked($group['all_belum_setor_selected'])
                                title="{{ __('Pilih semua transaksi belum setor di grup ini') }}"
                                class="rounded border-gray-300">
                        @endif
                        <button type="button" x-on:click="open = !open" class="flex items-center gap-2 text-left">
                            <span class="text-gray-400" x-text="open ? '▾' : '▸'"></span>
                            <span>
                                <span class="font-medium text-gray-800">{{ $group['referrer']?->name ?? '—' }}</span>
                                @if ($group['referrer']?->phone)
                                    <span class="block text-xs text-gray-400">{{ $group['referrer']->phone }}</span>
                                @endif
                            </span>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <span class="text-gray-500">{{ $group['tx_count'] }} {{ __('transaksi') }}</span>
                        <span>
                            <span class="text-gray-500">{{ __('Belum setor') }}:</span>
                            <span class="font-semibold text-orange-700">{{ $rupiah($group['total_belum_setor']) }}</span>
                        </span>
                        @if ($canManage && $group['payable_count'] > 0 && $group['referrer'])
                            <button type="button"
                                wire:click="openPayReferrerModal({{ $group['referrer']->id }})"
                                class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 whitespace-nowrap">
                                {{ __('Bayar Semua yang Bisa Dibayar') }} ({{ $group['payable_count'] }})
                            </button>
                        @endif
                    </div>
                </div>

                <div x-show="open" x-cloak class="border-t border-gray-100 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 w-8"></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Uang Diterima') }}</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Setoran') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pembayaran Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Dicatat') }}</th>
                                @if ($canManage)
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($group['rows'] as $entry)
                                @php
                                    [$badgeClass, $badgeLabel] = $statusBadge($entry->status);
                                    $sudahSetor = $entry->deposit_status === TitipDepositStatus::SudahSetor;
                                    $selectable = $entry->deposit_status === TitipDepositStatus::BelumSetor;
                                    $payableRow = $isPayable($entry);
                                @endphp
                                <tr wire:key="titip-{{ $entry->id }}">
                                    <td class="px-4 py-2">
                                        @if ($canManage && $selectable)
                                            <input type="checkbox" value="{{ $entry->id }}" wire:model.live="selected"
                                                class="rounded border-gray-300">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right text-gray-800">
                                        {{ $entry->gross_amount !== null ? $rupiah($entry->gross_amount) : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-600">
                                        {{ $entry->amount !== null ? $rupiah($entry->amount) : '—' }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $sudahSetor ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800' }}">
                                            {{ $sudahSetor ? __('Sudah Setor') : __('Belum Setor') }}
                                        </span>
                                        @if ($sudahSetor && $entry->deposited_at)
                                            <span class="block text-xs text-gray-400">
                                                {{ $entry->deposited_at->format('d/m/Y') }}
                                                @if ($entry->depositedBy) · {{ $entry->depositedBy->name }} @endif
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($entry->status === \App\Enums\CommissionStatus::Paid)
                                            <span class="inline-block px-2 py-0.5 text-xs font-medium rounded bg-green-100 text-green-800">{{ __('Dibayar') }}</span>
                                            @if ($entry->paid_at)
                                                <span class="block text-xs text-gray-400">
                                                    {{ $entry->paid_at->format('d/m/Y H:i') }}
                                                    @if ($entry->paidBy) · {{ $entry->paidBy->name }} @endif
                                                </span>
                                            @endif
                                            @if ($entry->payment_proof_path)
                                                <a href="{{ route('web.commission-payment-proofs.show', $entry->id) }}" target="_blank" class="text-xs text-blue-600 hover:underline">{{ __('Lihat Bukti') }}</a>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-500">{{ $entry->created_at->format('d/m/Y H:i') }}</td>
                                    @if ($canManage)
                                        <td class="px-4 py-2">
                                            @if ($payableRow)
                                                <button type="button"
                                                    wire:click="openPayRowModal({{ $entry->id }})"
                                                    class="px-2 py-1 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 whitespace-nowrap">
                                                    {{ __('Bayar Komisi Sekarang') }}
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm text-gray-500 border border-gray-200 rounded-md bg-white">
                {{ __('Belum ada fee komisi tercatat.') }}
            </div>
        @endforelse
    </div>

    {{-- ---------- Modal "Bayar Komisi Sekarang" (per baris ATAU per grup Referrer) ---------- --}}
    @if ($payingLedgerId !== null || $payingReferrerId !== null)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closePayModal">
            <div class="bg-white rounded-md shadow-lg w-full max-w-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ __('Bayar Komisi Sekarang') }}</h2>
                <p class="text-sm text-gray-500 mb-4">
                    {{ __('Unggah 1 foto bukti bayar (transfer/cash) sebelum menandai komisi ini sebagai dibayar. Aksi ini instan — tidak ada jendela waktu, tapi hanya berlaku untuk komisi Titip yang statusnya sudah "Layak Dibayar" dan setorannya sudah "Sudah Setor".') }}
                </p>

                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Bukti Bayar') }}</label>
                <input type="file" wire:model="paymentProof" accept="image/*" class="block w-full text-sm text-gray-600 mb-1">
                @if ($paymentProof)
                    <img src="{{ $paymentProof->temporaryUrl() }}" class="mt-2 max-h-40 rounded-md border border-gray-200" alt="Pratinjau bukti bayar">
                @endif
                @error('paymentProof') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" wire:click="closePayModal" class="px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-md">
                        {{ __('Batal') }}
                    </button>
                    @if ($payingLedgerId !== null)
                        <button type="button" wire:click="confirmPayRow" wire:loading.attr="disabled"
                            class="px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                            {{ __('Tandai Dibayar') }}
                        </button>
                    @else
                        <button type="button" wire:click="confirmPayReferrer" wire:loading.attr="disabled"
                            class="px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                            {{ __('Tandai Semua Dibayar') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
