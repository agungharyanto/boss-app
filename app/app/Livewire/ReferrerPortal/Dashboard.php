<?php

namespace App\Livewire\ReferrerPortal;

use App\Models\Customer;
use App\Models\Referrer;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use App\Services\Commission\ReferrerTitipService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * v0.9.2 — profil (nomor HP read-only, nama editable), daftar pelanggan
 * yang direferensikan, dan Rekap Komisi.
 *
 * v0.9.6 — Rekap Komisi diisi (SEMUA baris `commission_ledger` milik
 * Referrer, bukan `->first()`), plus alur "Catat Titip" self-service:
 * layar konfirmasi detail → OTP WhatsApp ke Referrer sendiri → submit →
 * baris `commission_ledger` scheme=titip status=eligible.
 *
 * CREATE-ONLY (CLAUDE.md): tidak ada aksi edit/hapus baris komisi di
 * portal ini. Koreksi = ranah admin.
 *
 * Referrer aktif di-resolve sekali oleh EnsureReferrerPortalAccess dan
 * disimpan di request (`referrer` attribute) — komponen ini membacanya
 * dari sana, lalu re-authorize sendiri (defense in depth).
 */
#[Layout('layouts.referrer-portal')]
class Dashboard extends Component
{
    public int $referrerId;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $phone = '';

    public string $typeLabel = '';

    public bool $nameUpdated = false;

    // --- Alur Catat Titip ---------------------------------------------------

    /** Pelanggan yang alur Titip-nya sedang terbuka; null = tidak ada. */
    public ?int $titipCustomerId = null;

    /** '' | 'confirm' | 'otp' */
    public string $titipStage = '';

    public string $otpCode = '';

    /** Sudah ada entri Titip bulan ini untuk pelanggan ini? */
    public bool $titipDuplicateWarning = false;

    /** Referrer sudah men-centang "saya tetap ingin lanjut" pada peringatan duplikat. */
    public bool $titipDuplicateAcknowledged = false;

    public ?string $titipSuccessMessage = null;

    public ?string $titipErrorMessage = null;

    public bool $otpResent = false;

    public function mount(): void
    {
        // Middleware EnsureReferrerPortalAccess sudah menaruh Referrer aktif
        // di request; fallback ke lookup by auth id (defense in depth +
        // testable tanpa menjalankan middleware).
        $referrer = request()->attributes->get('referrer')
            ?? Referrer::where('user_id', auth()->id())->where('is_active', true)->first();

        abort_if($referrer === null, 403);

        $this->referrerId = $referrer->id;
        $this->name = $referrer->name;
        $this->phone = $referrer->phone;
        $this->typeLabel = $referrer->type->label();
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

        $referrer = $this->referrer();
        $customer = $this->ownReferral($referrer, $customerId);

        $availability = $titip->availabilityFor($referrer, $customer);
        abort_unless($availability['available'], 422, $availability['reason'] ?? 'Titip tidak tersedia.');

        $this->titipCustomerId = $customerId;
        $this->titipStage = 'confirm';
        $this->titipDuplicateWarning = $titip->existingForMonth($referrer, $customer) !== null;
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
        $customer = $this->ownReferral($referrer, $this->titipCustomerId);

        try {
            $otp->issue($referrer, $this->titipScope(), $customer);
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
        $customer = $this->ownReferral($referrer, $this->titipCustomerId);

        try {
            $otp->issue($referrer, $this->titipScope(), $customer);
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
        $customer = $this->ownReferral($referrer, $this->titipCustomerId);

        try {
            $otp->verify($referrer, $this->titipScope(), $this->otpCode);
        } catch (ReferrerOtpException $e) {
            $this->addError('otpCode', $e->getMessage());

            return;
        }

        try {
            $ledger = $titip->record($referrer, $customer);
        } catch (\RuntimeException $e) {
            // Titip berhenti tersedia di antara startTitip() dan sekarang
            // (mis. admin menghapus rate). OTP sudah terlanjur dipakai —
            // batal dengan pesan jelas.
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

    private function ownReferral(Referrer $referrer, int $customerId): Customer
    {
        $customer = $referrer->referrals()->find($customerId);

        abort_if($customer === null, 404);

        return $customer;
    }

    public function render(): View
    {
        $referrer = Referrer::findOrFail($this->referrerId);
        $titip = app(ReferrerTitipService::class);

        $referrals = $referrer->referrals()->with('pppPackage')->latest()->get();

        $titipAvailability = $referrals->mapWithKeys(
            fn (Customer $c) => [$c->id => $titip->availabilityFor($referrer, $c)]
        );

        $commissionEntries = $referrer->commissionLedgerEntries()
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->get();

        $confirmCustomer = $this->titipCustomerId !== null
            ? $referrals->firstWhere('id', $this->titipCustomerId)
            : null;

        return view('livewire.referrer-portal.dashboard', [
            'referrals' => $referrals,
            'titipAvailability' => $titipAvailability,
            'commissionEntries' => $commissionEntries,
            'confirmCustomer' => $confirmCustomer,
            'confirmAmount' => $confirmCustomer !== null
                ? ($titipAvailability[$confirmCustomer->id]['amount'] ?? null)
                : null,
        ]);
    }
}
