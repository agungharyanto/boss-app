@php
    use App\Enums\CommissionScheme;
    use App\Enums\CommissionStatus;
    use App\Enums\TitipDepositStatus;

    $statusBadge = function (CommissionStatus $status): array {
        return match ($status) {
            CommissionStatus::Pending => ['bg-gray-100 text-gray-700', 'Menunggu'],
            CommissionStatus::Eligible => ['bg-blue-100 text-blue-800', 'Layak Dibayar'],
            CommissionStatus::Approved => ['bg-amber-100 text-amber-800', 'Disetujui'],
            CommissionStatus::Paid => ['bg-green-100 text-green-800', 'Dibayar'],
            CommissionStatus::Rejected => ['bg-red-100 text-red-800', 'Ditolak'],
            CommissionStatus::Clawback => ['bg-purple-100 text-purple-800', 'Dibatalkan'],
        };
    };
    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
@endphp

<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Fee Komisi') }}</h1>
    </div>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Semua komisi Referrer di satu tempat — Titip (cash pelanggan yang dipegang Referrer, dicatat lewat Perpanjang) dan Bulanan (Per Bulan / X-Kali, matang otomatis tiap invoice pelanggan lunas). Komisi Bulanan wajib di-Approve admin dulu sebelum bisa dibayar; Titip langsung layak dibayar setelah OTP. Kolom "Uang Diterima" & "Setoran" hanya relevan untuk Titip.') }}
    </p>

    @if ($flash)
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">{{ $flash }}</p>
    @endif
    @error('monthlyPay') <p class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{{ $message }}</p> @enderror
    @error('review') <p class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">{{ $message }}</p> @enderror

    {{-- ---------- Kartu ringkasan ---------- --}}
    <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 mb-3">
        {{ __('Komisi & Setoran adalah 2 arus uang BERBEDA: "Setoran Belum Masuk" = cash pelanggan yang masih dipegang Referrer (Referrer → perusahaan); "Harus Dibayar" = komisi yang perusahaan bayar balik ke Referrer. Menandai "Sudah Setor" tidak mengubah angka "Harus Dibayar".') }}
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Komisi Titip Harus Dibayar') }}</p>
            <p class="mt-1 text-xl font-semibold text-blue-700">{{ $rupiah($totalTitipHarusDibayar) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Status "Layak Dibayar", skema Titip.') }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Setoran Titip Belum Masuk') }}</p>
            <p class="mt-1 text-xl font-semibold text-orange-700">{{ $rupiah($totalSetoranBelumMasuk) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Cash pelanggan masih dipegang Referrer.') }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Bulanan Menunggu Approval') }}</p>
            <p class="mt-1 text-xl font-semibold text-amber-700">{{ $rupiah($totalBulananMenungguApproval) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Eligible, belum di-Approve — belum bisa dibayar.') }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Bulanan Siap Dibayar') }}</p>
            <p class="mt-1 text-xl font-semibold text-indigo-700">{{ $rupiah($totalBulananSiapDibayar) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Sudah Disetujui — tinggal payout (cek jendela).') }}</p>
        </div>
        <div class="p-4 bg-white border border-gray-200 rounded-md">
            <p class="text-xs text-gray-500 uppercase">{{ __('Total Komisi Harus Dibayar') }}</p>
            <p class="mt-1 text-xl font-semibold text-gray-800">{{ $rupiah($totalGabungan) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Titip + seluruh Bulanan (menunggu + siap).') }}</p>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3 mb-4">
        <input
            type="text" wire:key="fk-search" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan / referrer...') }}"
            class="flex-1 min-w-[12rem] rounded-md border-gray-300 shadow-sm"
        >
        <select wire:key="fk-scheme-filter" wire:model.live="schemeFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua jenis komisi') }}</option>
            <option value="titip">{{ __('Titip') }}</option>
            <option value="bulanan">{{ __('Bulanan (Per Bulan / X-Kali)') }}</option>
        </select>
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
                                title="{{ __('Pilih semua transaksi Titip belum setor di grup ini') }}"
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

                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="text-gray-500">{{ $group['tx_count'] }} {{ __('baris') }}</span>
                        @if ($group['monthly_review_count'] > 0)
                            <span class="px-2 py-0.5 text-xs font-medium rounded bg-amber-100 text-amber-800">
                                {{ $group['monthly_review_count'] }} {{ __('menunggu approval') }}
                            </span>
                        @endif
                        <span>
                            <span class="text-gray-500">{{ __('Belum setor') }}:</span>
                            <span class="font-semibold text-orange-700">{{ $rupiah($group['total_belum_setor']) }}</span>
                        </span>
                        @if ($canManage && $group['payable_count'] > 0 && $group['referrer'])
                            <button type="button"
                                wire:click="openPayReferrerModal({{ $group['referrer']->id }})"
                                class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 whitespace-nowrap">
                                {{ __('Bayar Titip yang Bisa Dibayar') }} ({{ $group['payable_count'] }})
                            </button>
                        @endif
                        @if ($canManage && $group['monthly_payable_count'] > 0 && $group['referrer'])
                            <button type="button"
                                wire:click="payMonthlyReferrer({{ $group['referrer']->id }})"
                                wire:confirm="{{ __('Bayar semua komisi bulanan (Disetujui) yang jendelanya terbuka untuk Referrer ini?') }}"
                                class="px-3 py-1.5 text-xs font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700 whitespace-nowrap">
                                {{ __('Bayar Bulanan yang Bisa Dibayar') }} ({{ $group['monthly_payable_count'] }})
                            </button>
                        @endif
                    </div>
                </div>

                <div x-show="open" x-cloak class="border-t border-gray-100 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 w-8"></th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jenis') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode / Invoice') }}</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Uang Diterima') }}</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status Komisi') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Setoran') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pembayaran Komisi') }}</th>
                                @if ($canManage || $canApprove || $canClawback)
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($group['rows'] as $entry)
                                @php
                                    [$badgeClass, $badgeLabel] = $statusBadge($entry->status);
                                    $isTitipRow = $isTitip($entry);
                                    $isClawbackRow = $entry->status === CommissionStatus::Clawback;
                                    $sudahSetor = $entry->deposit_status === TitipDepositStatus::SudahSetor;
                                    $selectable = $isTitipRow && $entry->deposit_status === TitipDepositStatus::BelumSetor && ! $isClawbackRow;
                                    $titipPayableRow = $isPayable($entry);
                                    $monthlyReviewableRow = $isMonthlyReviewable($entry);
                                    $monthlyPayableRow = $isMonthlyPayableNow($entry);
                                    $clawbackableRow = $isClawbackable($entry);
                                    $reversedPaid = $isClawbackRow && $entry->reversalOf?->status === CommissionStatus::Paid;
                                @endphp
                                <tr wire:key="fk-{{ $entry->id }}" @class(['bg-purple-50/40' => $isClawbackRow])>
                                    <td class="px-4 py-2">
                                        @if ($canManage && $selectable)
                                            <input type="checkbox" value="{{ $entry->id }}" wire:model.live="selected"
                                                class="rounded border-gray-300">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        <span @class([
                                            'inline-block px-2 py-0.5 text-xs font-medium rounded',
                                            'bg-blue-50 text-blue-700' => $isTitipRow && ! $isClawbackRow,
                                            'bg-indigo-50 text-indigo-700' => ! $isTitipRow && ! $isClawbackRow,
                                            'bg-purple-50 text-purple-700' => $isClawbackRow,
                                        ])>
                                            {{ $entry->scheme?->label() ?? '—' }}@if ($isClawbackRow) · {{ __('pembatalan') }}@endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-800">{{ $entry->customer?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-600">
                                        @if ($isClawbackRow)
                                            <span class="text-xs text-purple-700">{{ __('membatalkan baris') }} #{{ $entry->reversal_of_id }}</span>
                                        @elseif ($isTitipRow)
                                            {{ $entry->payment_period?->translatedFormat('F Y') ?? '—' }}
                                        @else
                                            <span class="font-mono text-xs">{{ $entry->invoice?->invoice_number ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-800">
                                        {{ $isTitipRow && $entry->gross_amount !== null ? $rupiah($entry->gross_amount) : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right {{ $isClawbackRow ? 'text-purple-700 font-medium' : 'text-gray-600' }}">
                                        {{ $entry->amount !== null ? $rupiah($entry->amount) : '—' }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                        @if ($reversedPaid)
                                            <span class="block mt-1 text-xs font-medium text-red-700">⚠ {{ __('Utang: komisi asli sudah dibayar, perlu ditagih balik') }}</span>
                                        @endif
                                        @if ($entry->wasClawedBack())
                                            <span class="block mt-1 text-xs text-purple-600">{{ __('Sudah dibatalkan (clawback)') }}</span>
                                        @endif
                                        @if ($entry->status === CommissionStatus::Rejected && $entry->reviewedBy)
                                            <span class="block text-xs text-gray-400">{{ __('oleh') }} {{ $entry->reviewedBy->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($isTitipRow && ! $isClawbackRow)
                                            <span class="inline-block px-2 py-0.5 text-xs font-medium rounded {{ $sudahSetor ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800' }}">
                                                {{ $sudahSetor ? __('Sudah Setor') : __('Belum Setor') }}
                                            </span>
                                            @if ($sudahSetor && $entry->deposited_at)
                                                <span class="block text-xs text-gray-400">
                                                    {{ $entry->deposited_at->format('d/m/Y') }}
                                                    @if ($entry->depositedBy) · {{ $entry->depositedBy->name }} @endif
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($entry->status === CommissionStatus::Paid)
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
                                    @if ($canManage || $canApprove || $canClawback)
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                @if ($canApprove && $monthlyReviewableRow)
                                                    <button type="button"
                                                        wire:click="approve({{ $entry->id }})"
                                                        wire:confirm="{{ __('Setujui komisi bulanan ini?') }}"
                                                        class="px-2 py-1 text-xs font-medium bg-amber-600 text-white rounded-md hover:bg-amber-700">
                                                        {{ __('Approve') }}
                                                    </button>
                                                    <button type="button"
                                                        wire:click="openRejectModal({{ $entry->id }})"
                                                        class="text-xs text-red-600 hover:underline">
                                                        {{ __('Reject') }}
                                                    </button>
                                                @elseif ($canManage && $titipPayableRow)
                                                    <button type="button"
                                                        wire:click="openPayRowModal({{ $entry->id }})"
                                                        class="px-2 py-1 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                                        {{ __('Bayar Komisi') }}
                                                    </button>
                                                @elseif ($canManage && $monthlyPayableRow)
                                                    <button type="button"
                                                        wire:click="payMonthlyRow({{ $entry->id }})"
                                                        wire:confirm="{{ __('Tandai komisi bulanan ini sebagai dibayar?') }}"
                                                        class="px-2 py-1 text-xs font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                                                        {{ __('Bayar Komisi') }}
                                                    </button>
                                                @elseif (! $isClawbackRow && $entry->scheme !== CommissionScheme::Titip && $entry->status === CommissionStatus::Approved)
                                                    <span class="text-xs text-gray-400" title="{{ __('Jendela payout paket ini sedang tertutup — atur di Rate Komisi.') }}">{{ __('Jendela tertutup') }}</span>
                                                @endif

                                                @if ($canClawback && $clawbackableRow)
                                                    <button type="button"
                                                        wire:click="openClawbackModal({{ $entry->id }})"
                                                        class="text-xs text-purple-700 hover:underline">
                                                        {{ __('Batalkan') }}
                                                    </button>
                                                @endif
                                            </div>
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

    {{-- ---------- Modal "Bayar Komisi Sekarang" (Titip — per baris ATAU per grup Referrer) ---------- --}}
    @if ($payingLedgerId !== null || $payingReferrerId !== null)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closePayModal">
            <div class="bg-white rounded-md shadow-lg w-full max-w-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ __('Bayar Komisi Titip') }}</h2>
                <p class="text-sm text-gray-500 mb-4">
                    {{ __('Unggah 1 foto bukti bayar (transfer/cash) sebelum menandai komisi ini sebagai dibayar. Aksi ini instan — hanya berlaku untuk komisi Titip yang statusnya "Layak Dibayar" dan setorannya sudah "Sudah Setor".') }}
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

    {{-- ---------- Modal Reject (komisi bulanan) ---------- --}}
    @if ($rejectingLedgerId !== null)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeRejectModal">
            <div class="bg-white rounded-md shadow-lg w-full max-w-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ __('Tolak Komisi Bulanan') }}</h2>
                <p class="text-sm text-gray-500 mb-4">
                    {{ __('Komisi yang ditolak TIDAK akan pernah masuk payout. Alasan disimpan sebagai jejak audit di catatan baris.') }}
                </p>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Alasan Penolakan') }}</label>
                <textarea wire:model="rejectReason" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm text-sm"
                    placeholder="{{ __('mis. pelanggan batal berlangganan sebelum instalasi') }}"></textarea>
                @error('rejectReason') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" wire:click="closeRejectModal" class="px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-md">{{ __('Batal') }}</button>
                    <button type="button" wire:click="confirmReject" wire:loading.attr="disabled"
                        class="px-3 py-2 text-sm font-medium bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50">
                        {{ __('Tolak Komisi') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ---------- Modal Clawback (semua skema) ---------- --}}
    @if ($clawbackLedgerId !== null)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeClawbackModal">
            <div class="bg-white rounded-md shadow-lg w-full max-w-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ __('Batalkan Komisi (Clawback)') }}</h2>
                <p class="text-sm text-gray-500 mb-4">
                    {{ __('Membuat baris pembatalan bernilai negatif yang me-reverse komisi ini — baris asli TETAP tersimpan sebagai jejak. Kalau komisi asli sudah dibayar, sistem TIDAK menarik uang otomatis; hanya dicatat sebagai utang yang perlu ditagih balik ke Referrer.') }}
                </p>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Alasan Pembatalan') }}</label>
                <textarea wire:model="clawbackReason" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm text-sm"
                    placeholder="{{ __('mis. pelanggan refund / koreksi salah input paket') }}"></textarea>
                @error('clawbackReason') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" wire:click="closeClawbackModal" class="px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-md">{{ __('Batal') }}</button>
                    <button type="button" wire:click="confirmClawback" wire:loading.attr="disabled"
                        class="px-3 py-2 text-sm font-medium bg-purple-700 text-white rounded-md hover:bg-purple-800 disabled:opacity-50">
                        {{ __('Batalkan Komisi') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
