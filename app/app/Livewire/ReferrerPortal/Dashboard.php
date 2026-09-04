<?php

namespace App\Livewire\ReferrerPortal;

use App\Enums\CommissionScheme;
use App\Models\Customer;
use App\Models\Referrer;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use App\Services\Commission\ReferrerTitipService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * v0.9.2 — profil (nomor HP read-only, nama editable) + Rekap Komisi.
 *
 * v0.9.6 — alur "Catat Titip" self-service (konfirmasi detail → OTP WhatsApp
 * ke Referrer sendiri → submit → baris `commission_ledger` scheme=titip
 * status=eligible).
 *
 * v0.9.6 (perluasan, keputusan Agung) — daftar pelanggan diperluas dari
 * "pelanggan yang direferensikan Referrer ini" jadi **SEMUA pelanggan
 * (tenant-scoped)**: titip pembayaran cash bisa dikumpulkan siapa saja
 * (Sales/Teknisi/Agent), tidak harus Referrer resmi pelanggan itu. Kolom
 * "Referensi" (Referrer resmi) hanya konteks, bukan filter. Rekap dipecah
 * jadi 2 tabel: "Rekap Komisi" (scheme != titip) dan "Rekap Titip"
 * (scheme = titip).
 *
 * CREATE-ONLY (CLAUDE.md): tidak ada aksi edit/hapus baris komisi di sini.
 *
 * Referrer aktif di-resolve sekali oleh EnsureReferrerPortalAccess dan
 * disimpan di request (`referrer` attribute) — komponen ini membacanya dari
 * sana, lalu re-authorize sendiri (defense in depth).
 */
#[Layout('layouts.referrer-portal')]
class Dashboard extends Component
{
    use WithPagination;

    public int $referrerId;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $phone = '';

    public string $typeLabel = '';

    public bool $nameUpdated = false;

    public string $search = '';

    // --- Alur Catat Titip ---------------------------------------------------

    /** Pelanggan yang alur Titip-nya sedang terbuka; null = tidak ada. */
    public ?int $titipCustomerId = null;

    /** '' | 'confirm' | 'otp' */
    public string $titipStage = '';

    public string $otpCode = '';

    /** Sudah ada entri Titip bulan ini untuk pelanggan ini (oleh siapa pun)? */
    public bool $titipDuplicateWarning = false;

    public bool $titipDuplicateAcknowledged = false;

    public ?string $titipSuccessMessage = null;

    public ?string $titipErrorMessage = null;

    public bool $otpResent = false;

    public function mount(): void
    {
        $referrer = request()->attributes->get('referrer')
            ?? Referrer::where('user_id', auth()->id())->where('is_active', true)->first();

        abort_if($referrer === null, 403);

        $this->referrerId = $referrer->id;
        $this->name = $referrer->name;
        $this->phone = $referrer->phone;
        $this->typeLabel = $referrer->type->label();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updateName(): void
    {
        $referrer = $this->referrer();

        $this->validate();

        $referrer->update(['name' => $this->name]);

        $this->nameUpdated = true;
    }

    // --- Titip: langkah 1 (buka layar konfirmasi) -------------------------

    public function startTitip(int $customerId, ReferrerTitipService $titip): void
    {
        $this->resetTitipFlow();
        $this->titipSuccessMessage = null;
        $this->titipErrorMessage = null;

        $this->referrer(); // re-authorize
        $customer = $this->resolveCustomer($customerId);

        $availability = $titip->availabilityFor($customer);
        abort_unless($availability['available'], 422, $availability['reason'] ?? 'Titip tidak tersedia.');

        $this->titipCustomerId = $customerId;
        $this->titipStage = 'confirm';
        $this->titipDuplicateWarning = $titip->existingForMonth($customer) !== null;
    }

    // --- Titip: langkah 2 (kirim OTP) ------------------------------------

    public function sendTitipOtp(ReferrerActionOtpService $otp): void
    {
        if ($this->titipStage !== 'confirm' || $this->titipCustomerId === null) {
            return;
        }

        if ($this->titipDuplicateWarning && ! $this->titipDuplicateAcknowledged) {
            $this->addError('titipDuplicateAcknowledged', 'Centang dulu bahwa Anda tetap ingin mencatat titip untuk pelanggan ini bulan ini.');

            return;
        }

        $referrer = $this->referrer();
        $customer = $this->resolveCustomer($this->titipCustomerId);

        try {
            $otp->issue($referrer, $this->titipScope(), "mencatat titip pembayaran untuk {$customer->name}", $customer);
        } catch (ReferrerOtpException $e) {
            $this->addError('otpCode', $e->getMessage());

            return;
        }

        $this->titipStage = 'otp';
        $this->otpCode = '';
        $this->resetErrorBag('otpCode');
    }

    public function resendTitipOtp(ReferrerActionOtpService $otp): void
    {
        if ($this->titipStage !== 'otp' || $this->titipCustomerId === null) {
            return;
        }

        $referrer = $this->referrer();
        $customer = $this->resolveCustomer($this->titipCustomerId);

        try {
            $otp->issue($referrer, $this->titipScope(), "mencatat titip pembayaran untuk {$customer->name}", $customer);
        } catch (ReferrerOtpException $e) {
            $this->addError('otpCode', $e->getMessage());

            return;
        }

        $this->otpResent = true;
        $this->resetErrorBag('otpCode');
    }

    // --- Titip: langkah 3 (verifikasi + catat) --------------------------

    public function submitTitip(ReferrerActionOtpService $otp, ReferrerTitipService $titip): void
    {
        if ($this->titipStage !== 'otp' || $this->titipCustomerId === null) {
            return;
        }

        $referrer = $this->referrer();
        $customer = $this->resolveCustomer($this->titipCustomerId);

        try {
            $otp->verify($referrer, $this->titipScope(), $this->otpCode);
        } catch (ReferrerOtpException $e) {
            $this->addError('otpCode', $e->getMessage());

            return;
        }

        try {
            $ledger = $titip->record($referrer, $customer);
        } catch (\RuntimeException $e) {
            $this->resetTitipFlow();
            $this->titipErrorMessage = $e->getMessage();

            return;
        }

        $this->resetTitipFlow();
        $this->titipSuccessMessage = "Titip untuk {$customer->name} sebesar Rp ".number_format((float) $ledger->amount, 0, ',', '.').' berhasil dicatat.';
    }

    public function cancelTitip(): void
    {
        $this->resetTitipFlow();
    }

    private function resetTitipFlow(): void
    {
        $this->titipCustomerId = null;
        $this->titipStage = '';
        $this->otpCode = '';
        $this->titipDuplicateWarning = false;
        $this->titipDuplicateAcknowledged = false;
        $this->otpResent = false;
        $this->resetErrorBag(['otpCode', 'titipDuplicateAcknowledged']);
    }

    private function titipScope(): string
    {
        return "titip:{$this->titipCustomerId}";
    }

    private function referrer(): Referrer
    {
        $referrer = Referrer::findOrFail($this->referrerId);

        abort_if($referrer->user_id !== auth()->id(), 403);

        return $referrer;
    }

    /**
     * Pelanggan mana pun di tenant yang sama (global scope BelongsToTenant
     * memfilter otomatis lewat `Auth::user()->tenant_id`). BEDA dari v0.9.6
     * awal yang membatasi ke pelanggan yang direferensikan Referrer ini.
     */
    private function resolveCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->find($customerId);

        abort_if($customer === null, 404);

        return $customer;
    }

    public function render(): View
    {
        $referrer = Referrer::findOrFail($this->referrerId);
        $titip = app(ReferrerTitipService::class);

        $customers = Customer::query()
            ->with(['pppPackage:id,name', 'referredBy:id,name'])
            ->when($this->search !== '', function ($q) {
                $s = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhere('cid', 'like', $s)
                    ->orWhere('phone_number', 'like', $s));
            })
            ->orderBy('name')
            ->paginate(15);

        $titipAvailability = collect($customers->items())->mapWithKeys(
            fn (Customer $c) => [$c->id => $titip->availabilityFor($c)]
        );

        $ledger = $referrer->commissionLedgerEntries()
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->get();

        $isTitip = fn ($e) => $e->scheme?->value === CommissionScheme::Titip->value;

        $confirmCustomer = $this->titipCustomerId !== null
            ? Customer::query()->find($this->titipCustomerId)
            : null;

        return view('livewire.referrer-portal.dashboard', [
            'customers' => $customers,
            'titipAvailability' => $titipAvailability,
            'commissionEntries' => $ledger->reject($isTitip)->values(),
            'titipEntries' => $ledger->filter($isTitip)->values(),
            'confirmCustomer' => $confirmCustomer,
            'confirmAmount' => $confirmCustomer !== null
                ? ($titip->availabilityFor($confirmCustomer)['amount'] ?? null)
                : null,
        ]);
    }
}
