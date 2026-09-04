<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\CreateCustomerAction;
use App\Enums\CommissionScheme;
use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use App\Services\Commission\ReferrerTitipService;
use App\Services\Commission\SubscriptionRenewalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar Pelanggan — halaman admin, tapi sejak sprint
 * "perpanjang-daftar-pelanggan" juga bisa diakses akun Referrer murni
 * (gerbang `customers.list`, lihat EnsureCustomerListAccess). Untuk user
 * TANPA akses panel admin, komponen ini merender versi sederhana:
 * layout tanpa sidebar (`layouts.referrer-portal`), tabel list saja, tanpa
 * tombol buat/registrasi pelanggan, tanpa link Detail. Baik admin maupun
 * Referrer mendapat tombol "Perpanjang" per baris.
 *
 * "Perpanjang": opsional ganti paket (`customers.ppp_package_id` SAJA — nol
 * panggilan NAS/RADIUS/MixRadius) + OTP WhatsApp ke acting Referrer +
 * komisi Titip kalau acting Referrer bertipe Sales/Freelance. Lihat
 * SubscriptionRenewalService.
 *
 * Mode admin vs Referrer di-derive ULANG tiap request dari
 * `auth()->user()` (bukan properti persisted yang bisa dimanipulasi klien);
 * setiap aksi tetap di-authorize server-side terlepas dari mode.
 */
class CustomerIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $address = '';

    #[Validate('required|string|max:20')]
    public string $phone_number = '';

    // --- Alur Perpanjang -------------------------------------------------

    public ?int $renewCustomerId = null;

    public bool $renewModalOpen = false;

    /** '' = tidak ganti paket. */
    public string $renewNewPackageId = '';

    /** Multi-bulan (ADMIN ONLY). Referrer self-service selalu implisit 1. */
    public int $renewMonths = 1;

    /** Bulan awal rentang, format `Y-m` (ADMIN ONLY). '' = bulan berjalan. */
    public string $renewStartPeriod = '';

    public string $renewOtp = '';

    public bool $renewOtpSent = false;

    public bool $renewOtpResent = false;

    public bool $renewOtpVerified = false;

    /** True kalau pelanggan sudah punya baris titip untuk periode bulan ini. */
    public bool $renewAlreadyPaidThisMonth = false;

    public ?string $renewFlash = null;

    public ?string $renewError = null;

    public function mount(): void
    {
        if ($this->isAdmin()) {
            $this->authorize('viewAny', Customer::class);

            return;
        }

        // Referrer murni — `customers.list` middleware sudah mengonfirmasi
        // akun tertaut Referrer aktif. `Livewire::test()` melewati
        // middleware, jadi cek ulang di sini juga.
        abort_if($this->actingReferrer() === null, 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function createCustomer(CreateCustomerAction $action): void
    {
        $this->authorize('create', Customer::class);

        $data = $this->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'phone_number' => 'required|string|max:20',
        ]);

        $action->handle($data);

        $this->reset(['name', 'address', 'phone_number', 'showCreateForm']);
    }

    // --- Perpanjang: buka modal -----------------------------------------

    public function openRenew(int $customerId, ReferrerTitipService $titip): void
    {
        $this->resetRenewFlow();
        $this->renewFlash = null;
        $this->renewError = null;

        $customer = $this->resolveCustomer($customerId);

        $this->renewCustomerId = $customer->id;
        $this->renewNewPackageId = '';
        $this->renewMonths = 1;
        $this->renewModalOpen = true;

        if ($this->isAdmin()) {
            // Default "Mulai dari Periode" = periode belum-terbayar paling
            // awal (payment_period titip terakhir + 1 bulan, atau bulan
            // berjalan kalau belum ada riwayat).
            $this->renewStartPeriod = $this->earliestUnpaidPeriodFor($customer);
            // Admin bisa memilih periode masa depan — cek per-periode
            // dilakukan saat submit, bukan blokir modal di awal.
            $this->renewAlreadyPaidThisMonth = false;
        } else {
            // Referrer self-service: implisit 1 bulan, periode berjalan.
            // Cek di AWAL — jangan biarkan proses OTP dulu baru ditolak.
            $this->renewStartPeriod = '';
            $this->renewAlreadyPaidThisMonth = $titip->existingForMonth($customer) !== null;
        }
    }

    private function earliestUnpaidPeriodFor(Customer $customer): string
    {
        $last = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->whereNotNull('payment_period')
            ->orderByDesc('payment_period')
            ->value('payment_period');

        $base = $last !== null
            ? Carbon::parse($last)->startOfMonth()->addMonth()
            : Carbon::now()->startOfMonth();

        // Jangan pernah mundur ke belakang bulan berjalan.
        $now = Carbon::now()->startOfMonth();

        return $base->lessThan($now)
            ? $now->format('Y-m')
            : $base->format('Y-m');
    }

    public function closeRenew(): void
    {
        $this->resetRenewFlow();
    }

    // --- Perpanjang: OTP ------------------------------------------------

    public function sendRenewOtp(ReferrerActionOtpService $otp): void
    {
        if (! $this->renewModalOpen || $this->renewCustomerId === null || $this->renewAlreadyPaidThisMonth) {
            return;
        }

        $referrer = $this->actingReferrer();

        if ($referrer === null) {
            $this->addError('renewOtp', 'Akun Anda tidak tertaut ke Referral, verifikasi OTP tidak tersedia. Anda tetap bisa mencatat perpanjangan sebagai admin.');

            return;
        }

        $customer = $this->resolveCustomer($this->renewCustomerId);

        try {
            $otp->issue($referrer, $this->renewScope(), "perpanjang langganan {$customer->name}", $customer);
        } catch (ReferrerOtpException $e) {
            $this->addError('renewOtp', $e->getMessage());

            return;
        }

        $this->renewOtpSent = true;
        $this->renewOtpVerified = false;
        $this->renewOtp = '';
        $this->resetErrorBag('renewOtp');
    }

    public function resendRenewOtp(ReferrerActionOtpService $otp): void
    {
        if (! $this->renewOtpSent || $this->renewCustomerId === null) {
            return;
        }

        $referrer = $this->actingReferrer();

        if ($referrer === null) {
            return;
        }

        $customer = $this->resolveCustomer($this->renewCustomerId);

        try {
            $otp->issue($referrer, $this->renewScope(), "perpanjang langganan {$customer->name}", $customer);
        } catch (ReferrerOtpException $e) {
            $this->addError('renewOtp', $e->getMessage());

            return;
        }

        $this->renewOtpResent = true;
        $this->resetErrorBag('renewOtp');
    }

    public function verifyRenewOtp(ReferrerActionOtpService $otp): void
    {
        if (! $this->renewOtpSent || $this->renewCustomerId === null) {
            return;
        }

        $referrer = $this->actingReferrer();

        if ($referrer === null) {
            return;
        }

        try {
            $otp->verify($referrer, $this->renewScope(), $this->renewOtp);
        } catch (ReferrerOtpException $e) {
            $this->renewOtpVerified = false;
            $this->addError('renewOtp', $e->getMessage());

            return;
        }

        $this->renewOtpVerified = true;
        $this->resetErrorBag('renewOtp');
    }

    // --- Perpanjang: submit -------------------------------------------

    public function submitRenew(SubscriptionRenewalService $service): void
    {
        if (! $this->renewModalOpen || $this->renewCustomerId === null) {
            return;
        }

        if ($this->renewAlreadyPaidThisMonth) {
            $this->renewError = 'Pelanggan ini sudah tercatat bayar untuk periode bulan ini — hubungi admin kalau ada kebutuhan koreksi.';

            return;
        }

        $customer = $this->resolveCustomer($this->renewCustomerId);
        $referrer = $this->actingReferrer();

        // Referrer terikat -> WAJIB OTP terverifikasi. Admin tanpa Referrer
        // terikat -> lolos lewat otoritas panel admin, tanpa OTP.
        if ($referrer !== null) {
            if (! $this->renewOtpVerified) {
                $this->addError('renewOtp', 'Verifikasi kode OTP dulu sebelum memperpanjang.');

                return;
            }
        } elseif (! $this->isAdmin()) {
            abort(403);
        }

        $newPackageId = $this->renewNewPackageId !== '' ? (int) $this->renewNewPackageId : null;

        // Multi-bulan HANYA dari jalur admin. Referrer self-service selalu
        // 1 bulan, periode berjalan (field-nya memang tidak ada di form-nya).
        $months = 1;
        $startPeriod = null;
        if ($this->isAdmin()) {
            $months = max(1, $this->renewMonths);
            if ($this->renewStartPeriod !== '') {
                try {
                    $startPeriod = Carbon::createFromFormat('Y-m', $this->renewStartPeriod)->startOfMonth();
                } catch (\Throwable) {
                    $this->renewError = 'Format "Mulai dari Periode" tidak valid.';

                    return;
                }
            }
        }

        try {
            $result = $service->renew(auth()->user(), $customer, $newPackageId, $months, $startPeriod);
        } catch (\RuntimeException $e) {
            $this->renewError = $e->getMessage();

            return;
        }

        $this->resetRenewFlow();

        $monthsText = $result['months'] > 1 ? " {$result['months']} bulan" : '';
        if ($result['commission_created']) {
            $total = number_format((float) $result['commission_total'], 0, ',', '.');
            $this->renewFlash = "Perpanjangan {$customer->name}{$monthsText} dicatat, komisi Titip Rp {$total} berhasil ditambahkan.";
        } else {
            $this->renewFlash = "Perpanjangan {$customer->name}{$monthsText} dicatat.";
        }
    }

    // --- Helpers ------------------------------------------------------

    private function resetRenewFlow(): void
    {
        $this->renewCustomerId = null;
        $this->renewModalOpen = false;
        $this->renewNewPackageId = '';
        $this->renewMonths = 1;
        $this->renewStartPeriod = '';
        $this->renewOtp = '';
        $this->renewOtpSent = false;
        $this->renewOtpResent = false;
        $this->renewOtpVerified = false;
        $this->renewAlreadyPaidThisMonth = false;
        $this->resetErrorBag('renewOtp');
    }

    private function renewScope(): string
    {
        return "renewal:{$this->renewCustomerId}";
    }

    private function isAdmin(): bool
    {
        return EnsureAdminPanelAccess::userHasAccess(auth()->user());
    }

    private function actingReferrer(): ?Referrer
    {
        return Referrer::where('user_id', auth()->id())->where('is_active', true)->first();
    }

    private function resolveCustomer(int $customerId): Customer
    {
        // Tenant-scoped (BelongsToTenant global scope filters by
        // auth user's tenant_id) — a cross-tenant id resolves to null -> 404.
        $customer = Customer::query()->find($customerId);

        abort_if($customer === null, 404);

        return $customer;
    }

    public function render()
    {
        $referrerView = ! $this->isAdmin();

        $customers = Customer::query()
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                // whereRaw('LOWER(...) LIKE ?') instead of a plain 'like' —
                // Postgres LIKE is case-sensitive by default, and this stays
                // portable to sqlite (what the test suite runs on) too,
                // unlike 'ilike' which sqlite doesn't understand.
                $needle = '%'.mb_strtolower($this->search).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(phone_number) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(cid, \'\')) LIKE ?', [$needle]);
            }))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->with('pppPackage:id,name')
            ->latest()
            ->paginate(15);

        $renewCustomer = $this->renewCustomerId !== null
            ? Customer::query()->with('pppPackage:id,name')->find($this->renewCustomerId)
            : null;

        return view('livewire.customers.customer-index', [
            'customers' => $customers,
            'referrerView' => $referrerView,
            'canCreate' => ! $referrerView && auth()->user()->can('create', Customer::class),
            'canRegister' => ! $referrerView && auth()->user()->can('register-customer'),
            'packages' => PppPackage::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'renewCustomer' => $renewCustomer,
            'actingReferrer' => $this->actingReferrer(),
        ])->layout($referrerView ? 'layouts.referrer-portal' : 'layouts.app');
    }
}
