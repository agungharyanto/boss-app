<?php

namespace App\Livewire\Resellers;

use App\Enums\ResellerUserRole;
use App\Models\Reseller;
use App\Models\User;
use App\Services\ResellerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ResellerShow extends Component
{
    use AuthorizesRequests;

    public Reseller $reseller;

    #[Validate('required|email')]
    public string $staffEmail = '';

    #[Validate('required|in:owner,staff')]
    public string $staffRole = 'staff';

    public function mount(Reseller $reseller): void
    {
        $this->authorize('manageUsers', $reseller);

        $this->reseller = $reseller;
    }

    public function attachStaff(ResellerService $service): void
    {
        $this->authorize('manageUsers', $this->reseller);

        $this->validate();

        $user = User::where('tenant_id', $this->reseller->tenant_id)
            ->where('email', $this->staffEmail)
            ->first();

        if ($user === null) {
            $this->addError('staffEmail', __('Tidak ada user dengan email tersebut di tenant ini.'));

            return;
        }

        $service->attachUser($this->reseller, $user, ResellerUserRole::from($this->staffRole));

        $this->reset(['staffEmail', 'staffRole']);
    }

    public function detachStaff(int $userId, ResellerService $service): void
    {
        $this->authorize('manageUsers', $this->reseller);

        $user = User::findOrFail($userId);

        $service->detachUser($this->reseller, $user);
    }

    public function render()
    {
        return view('livewire.resellers.reseller-show', [
            'staffMembers' => $this->reseller->users()->get(),
        ]);
    }
}
