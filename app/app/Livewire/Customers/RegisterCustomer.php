<?php

namespace App\Livewire\Customers;

use App\Models\Agent;
use App\Models\Customer;
use App\Services\RegistrationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class RegisterCustomer extends Component
{
    use AuthorizesRequests;

    public ?Agent $linkedAgent = null;

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

    #[Validate('nullable|string|max:255')]
    public string $package = '';

    public ?int $selectedAgentId = null;

    public function mount(): void
    {
        $this->authorize('register-customer');

        $this->linkedAgent = Agent::where('user_id', auth()->id())->first();

        if ($this->linkedAgent) {
            $this->selectedAgentId = $this->linkedAgent->id;
        }
    }

    public function register(RegistrationService $service)
    {
        $this->authorize('register-customer');

        $data = $this->validate();
        $data['nik'] = $data['nik'] ?: null;
        $data['package'] = $data['package'] ?: null;

        // Mirrors StoreRegistrationRequest's nik_hash-based uniqueness
        // check (Api/V1/RegistrationController's own entry point) — nik
        // itself is an `encrypted` cast, so a plain Rule::unique('customers',
        // 'nik') would never actually catch a duplicate.
        if ($data['nik'] && Customer::nikAlreadyExists($data['nik'], auth()->user()->tenant_id)) {
            $this->addError('nik', 'NIK sudah terdaftar.');

            return;
        }

        if ($this->linkedAgent) {
            $this->validate(['selectedAgentId' => 'required']);
            $agent = $this->linkedAgent;
        } else {
            $this->validate([
                'selectedAgentId' => ['nullable', 'integer', Rule::exists('agents', 'id')->where('tenant_id', auth()->user()->tenant_id)],
            ]);
            $agent = $this->selectedAgentId ? Agent::find($this->selectedAgentId) : null;
        }

        $customer = $service->register([
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'address' => $data['address'],
            'nik' => $data['nik'],
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'package' => $data['package'],
        ], $agent);

        session()->flash('status', "Pelanggan {$customer->name} berhasil diregistrasi.");

        return redirect()->route('web.customers.show', $customer);
    }

    public function render()
    {
        $availableAgents = $this->linkedAgent
            ? collect()
            : Agent::where('is_active', true)->orderBy('name')->get();

        return view('livewire.customers.register-customer', [
            'availableAgents' => $availableAgents,
        ]);
    }
}
