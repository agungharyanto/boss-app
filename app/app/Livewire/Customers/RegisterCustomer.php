<?php

namespace App\Livewire\Customers;

use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Services\RegistrationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class RegisterCustomer extends Component
{
    use AuthorizesRequests;

    public ?Referrer $linkedReferrer = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:20')]
    public string $phone_number = '';

    #[Validate('required|string')]
    public string $address = '';

    #[Validate('nullable|string|max:20')]
    public string $nik = '';

    #[Validate('nullable|numeric|between:-90,90')]
    public ?float $latitude = null;

    #[Validate('nullable|numeric|between:-180,180')]
    public ?float $longitude = null;

    /**
     * v0.9.4 — dulu input teks bebas `package`; sekarang dropdown PppPackage
     * aktif, disimpan ke customers.ppp_package_id. Kolom `customers.package`
     * lama dibiarkan NULL, tidak dipakai lagi.
     */
    public ?int $ppp_package_id = null;

    public ?int $selectedReferrerId = null;

    /** 'recurring' / 'limited_count' — hanya relevan kalau field skema muncul (lihat schemeState()). */
    public ?string $commissionScheme = null;

    public function mount(): void
    {
        $this->authorize('register-customer');

        $this->linkedReferrer = Referrer::where('user_id', auth()->id())->first();

        if ($this->linkedReferrer) {
            $this->selectedReferrerId = $this->linkedReferrer->id;
        }
    }

    public function updatedPppPackageId(): void
    {
        // Skema tergantung paket — reset saat paket berubah.
        $this->commissionScheme = null;
    }

    public function updatedSelectedReferrerId(): void
    {
        // Skema hanya relevan kalau ada referrer.
        $this->commissionScheme = null;
    }

    /**
     * @return array{referrer: ?Referrer, options: array<string, string>, show: bool}
     */
    private function schemeState(): array
    {
        $referrer = $this->linkedReferrer
            ?? ($this->selectedReferrerId ? Referrer::find($this->selectedReferrerId) : null);

        $options = [];

        if ($referrer !== null && $this->ppp_package_id !== null) {
            $rate = CommissionRate::where('ppp_package_id', $this->ppp_package_id)
                ->where('is_active', true)
                ->first();

            $options = $rate?->schemeOptions() ?? [];
        }

        return [
            'referrer' => $referrer,
            'options' => $options,
            'show' => $options !== [],
        ];
    }

    public function register(RegistrationService $service)
    {
        $this->authorize('register-customer');

        $data = $this->validate();
        $data['nik'] = $data['nik'] ?: null;

        if ($data['nik'] && Customer::nikAlreadyExists($data['nik'], auth()->user()->tenant_id)) {
            $this->addError('nik', 'NIK sudah terdaftar.');

            return;
        }

        $this->validate([
            'ppp_package_id' => [
                'nullable', 'integer',
                Rule::exists('ppp_packages', 'id')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        if ($this->linkedReferrer) {
            $this->validate(['selectedReferrerId' => 'required']);
            $referrer = $this->linkedReferrer;
        } else {
            $this->validate([
                'selectedReferrerId' => ['nullable', 'integer', Rule::exists('referrers', 'id')->where('tenant_id', auth()->user()->tenant_id)],
            ]);
            $referrer = $this->selectedReferrerId ? Referrer::find($this->selectedReferrerId) : null;
        }

        $scheme = $this->resolveScheme();

        $customer = $service->register([
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'address' => $data['address'],
            'nik' => $data['nik'],
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'ppp_package_id' => $this->ppp_package_id,
        ], $referrer, $scheme);

        session()->flash('status', "Pelanggan {$customer->name} berhasil diregistrasi.");

        return redirect()->route('web.customers.show', $customer);
    }

    /**
     * Skema hanya diteruskan kalau field-nya memang muncul (ada referrer +
     * paket + opsi valid) DAN nilai yang dipilih ada di daftar opsi. Kalau
     * tidak, null — RegistrationService/CommissionAttributionService jatuh
     * ke perilaku lama (amount NULL).
     */
    private function resolveScheme(): ?string
    {
        $state = $this->schemeState();

        if (! $state['show'] || $this->commissionScheme === null) {
            return null;
        }

        return array_key_exists($this->commissionScheme, $state['options']) ? $this->commissionScheme : null;
    }

    public function render()
    {
        $availableReferrers = $this->linkedReferrer
            ? collect()
            : Referrer::where('is_active', true)->orderBy('name')->get();

        $state = $this->schemeState();

        return view('livewire.customers.register-customer', [
            'availableReferrers' => $availableReferrers,
            'availablePackages' => PppPackage::where('is_active', true)->orderBy('name')->get(),
            'schemeOptions' => $state['options'],
            'showSchemeField' => $state['show'],
        ]);
    }
}
