<?php

namespace App\Livewire\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Models\CommissionLedger;
use App\Models\Referrer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bagian E (v0.9.12) — "Riwayat Pembayaran Komisi". Semua baris
 * `commission_ledger` yang SUDAH DIBAYAR (`status = Paid`), Titip maupun
 * Bulanan, dengan grafik total komisi dibayar per bulan (breakdown per
 * jenis). Read-only — tidak ada aksi pembayaran di sini (itu di "Fee
 * Komisi" / "Payout Bulanan").
 *
 * Chart pakai Chart.js yang SUDAH ada di codebase (`resources/js/app.js`,
 * pola `window.<name>` factory + `wire:ignore` + dispatched browser event),
 * bukan dependency baru.
 */
class CommissionPaymentHistory extends Component
{
    use AuthorizesRequests, WithPagination;

    public string $from = '';

    public string $to = '';

    public string $referrerId = '';

    /** '' = semua, 'titip', 'bulanan' */
    public string $schemeFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionLedger::class);

        $this->from = Carbon::now()->subMonths(5)->startOfMonth()->toDateString();
        $this->to = Carbon::now()->endOfMonth()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->dispatch('commission-paid-series-updated', series: $this->chartSeries());
    }

    /**
     * @return array{labels: list<string>, titip: list<float>, bulanan: list<float>}
     */
    private function chartSeries(): array
    {
        $rows = $this->baseQuery()
            ->whereNotNull('paid_at')
            ->get(['scheme', 'amount', 'paid_at']);

        $from = $this->from !== '' ? Carbon::parse($this->from)->startOfMonth() : Carbon::now()->subMonths(5)->startOfMonth();
        $to = $this->to !== '' ? Carbon::parse($this->to)->endOfMonth() : Carbon::now()->endOfMonth();

        /** @var array<string, array{titip: float, bulanan: float}> $buckets */
        $buckets = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonth()) {
            $buckets[$cursor->format('Y-m')] = ['titip' => 0.0, 'bulanan' => 0.0];
        }

        foreach ($rows as $row) {
            $key = $row->paid_at->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            $group = $row->scheme === CommissionScheme::Titip ? 'titip' : 'bulanan';
            $buckets[$key][$group] += (float) ($row->amount ?? 0);
        }

        return [
            'labels' => array_map(
                fn (string $k) => Carbon::createFromFormat('Y-m', $k)->translatedFormat('M Y'),
                array_keys($buckets),
            ),
            'titip' => array_values(array_map(fn ($b) => round($b['titip'], 2), $buckets)),
            'bulanan' => array_values(array_map(fn ($b) => round($b['bulanan'], 2), $buckets)),
        ];
    }

    private function baseQuery()
    {
        $monthly = [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value];

        return CommissionLedger::query()
            ->where('status', CommissionStatus::Paid->value)
            ->when($this->from !== '', fn ($q) => $q->whereDate('paid_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('paid_at', '<=', $this->to))
            ->when($this->referrerId !== '', fn ($q) => $q->where('referrer_id', (int) $this->referrerId))
            ->when($this->schemeFilter === 'titip', fn ($q) => $q->where('scheme', CommissionScheme::Titip->value))
            ->when($this->schemeFilter === 'bulanan', fn ($q) => $q->whereIn('scheme', $monthly));
    }

    public function render()
    {
        $rows = $this->baseQuery()
            ->with(['referrer:id,name', 'customer:id,name', 'paidBy:id,name', 'invoice:id,invoice_number'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(25);

        // ->toBase() supaya `scheme` tetap string mentah (bukan enum-cast
        // CommissionLedger) di hasil agregat.
        $totals = (clone $this->baseQuery())->toBase()
            ->selectRaw('scheme, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('scheme')
            ->get();

        $totalTitip = (float) ($totals->firstWhere('scheme', CommissionScheme::Titip->value)?->total ?? 0);
        $totalBulanan = (float) $totals
            ->whereIn('scheme', [CommissionScheme::Recurring->value, CommissionScheme::LimitedCount->value])
            ->sum('total');

        return view('livewire.commission.commission-payment-history', [
            'rows' => $rows,
            'referrers' => Referrer::orderBy('name')->get(['id', 'name']),
            'chartSeries' => $this->chartSeries(),
            'totalTitip' => $totalTitip,
            'totalBulanan' => $totalBulanan,
            'totalAll' => $totalTitip + $totalBulanan,
            'countAll' => (int) $totals->sum('cnt'),
        ]);
    }
}
