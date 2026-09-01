<?php

namespace App\Livewire\Commission;

use App\Models\CommissionRate;
use App\Models\PppPackage;
use App\Services\CommissionRateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.9.3 — Commission Rate Settings. Me-list SEMUA PppPackage (bukan hanya
 * yang sudah punya rate); rate ditampilkan kalau ada, form edit inline
 * (satu baris terbuka pada satu waktu, pola sama dengan
 * BandwidthProfileIndex) untuk isi/ubah rate per paket.
 *
 * Kondisi data saat dibangun: PppPackage aktif = 0 baris (hanya ada 1 baris
 * soft-deleted dari test lama), jadi halaman ini akan tampil kosong sampai
 * ada Profil PPP sungguhan dibuat lewat /ppp-packages — bukan bug.
 */
class CommissionRateIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    /** Paket yang form rate-nya sedang terbuka; null = tidak ada. */
    public ?int $editingPackageId = null;

    public ?string $recurringAmount = null;

    public ?string $limitedCountAmount = null;

    public ?string $limitedCountTimes = null;

    public ?string $titipAmount = null;

    public bool $rateIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionRate::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function edit(int $packageId): void
    {
        $this->authorize('manage', CommissionRate::class);

        $package = PppPackage::with('commissionRate')->findOrFail($packageId);
        $rate = $package->commissionRate;

        $this->editingPackageId = $package->id;
        $this->recurringAmount = $rate?->recurring_amount !== null ? (string) $rate->recurring_amount : null;
        $this->limitedCountAmount = $rate?->limited_count_amount !== null ? (string) $rate->limited_count_amount : null;
        $this->limitedCountTimes = $rate?->limited_count_times !== null ? (string) $rate->limited_count_times : null;
        $this->titipAmount = $rate?->titip_amount !== null ? (string) $rate->titip_amount : null;
        $this->rateIsActive = $rate?->is_active ?? true;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingPackageId', 'recurringAmount', 'limitedCountAmount',
            'limitedCountTimes', 'titipAmount', 'rateIsActive',
        ]);
        $this->rateIsActive = true;
        $this->resetErrorBag();
    }

    public function saveRate(CommissionRateService $service): void
    {
        $this->authorize('manage', CommissionRate::class);

        $package = PppPackage::with('commissionRate')->findOrFail($this->editingPackageId);

        foreach (['recurringAmount', 'limitedCountAmount', 'limitedCountTimes', 'titipAmount'] as $field) {
            if ($this->{$field} !== null && trim($this->{$field}) === '') {
                $this->{$field} = null;
            }
        }

        $this->validate([
            'recurringAmount' => ['nullable', 'numeric', 'min:0'],
            'limitedCountAmount' => ['nullable', 'numeric', 'min:0'],
            'limitedCountTimes' => ['nullable', 'integer', 'min:1'],
            'titipAmount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $map = [
            'recurring_amount' => 'recurringAmount',
            'limited_count_amount' => 'limitedCountAmount',
            'limited_count_times' => 'limitedCountTimes',
            'titip_amount' => 'titipAmount',
        ];
        $schemeErrors = CommissionRate::schemeErrors(
            $this->recurringAmount,
            $this->limitedCountAmount,
            $this->limitedCountTimes,
            $this->titipAmount,
        );
        foreach ($schemeErrors as $field => $message) {
            $this->addError($map[$field], $message);
        }
        if ($schemeErrors !== []) {
            return;
        }

        $data = [
            'recurring_amount' => $this->recurringAmount,
            'limited_count_amount' => $this->limitedCountAmount,
            'limited_count_times' => $this->limitedCountTimes,
            'titip_amount' => $this->titipAmount,
            'is_active' => $this->rateIsActive,
        ];

        if ($package->commissionRate !== null) {
            $service->update($package->commissionRate, $data);
        } else {
            $service->createForPackage($package, $data);
        }

        $this->cancelEdit();
    }

    public function deleteRate(int $packageId, CommissionRateService $service): void
    {
        $this->authorize('manage', CommissionRate::class);

        $rate = PppPackage::with('commissionRate')->findOrFail($packageId)->commissionRate;

        if ($rate !== null) {
            $service->delete($rate);
        }

        if ($this->editingPackageId === $packageId) {
            $this->cancelEdit();
        }
    }

    public function render()
    {
        $packages = PppPackage::query()
            ->with(['commissionRate', 'networkProfileGroup'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.commission.commission-rate-index', [
            'packages' => $packages,
            'canManage' => auth()->user()->can('manage', CommissionRate::class),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'recurringAmount' => 'Komisi Per Bulan',
            'limitedCountAmount' => 'Komisi Skema X-Kali',
            'limitedCountTimes' => 'Jumlah Kali Pembayaran',
            'titipAmount' => 'Komisi Titip',
        ];
    }
}
