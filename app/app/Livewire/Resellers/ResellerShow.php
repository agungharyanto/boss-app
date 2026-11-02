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

    // v0.19.1 — override nomor CS reseller (diedit admin lewat UI reseller
    // yang sudah ada; reseller TIDAK diberi hak baru). Brand/logo/legal
    // tetap milik tenant.
    #[Validate('nullable|string|max:30')]
    public string $csWhatsapp = '';

    #[Validate('nullable|string|max:30')]
    public string $csPhone = '';

    public function mount(Reseller $reseller): void
    {
        $this->authorize('manageUsers', $reseller);

        $this->reseller = $reseller;
        $this->csWhatsapp = (string) ($reseller->cs_whatsapp ?? '');
        $this->csPhone = (string) ($reseller->cs_phone ?? '');
    }

    public function saveCsOverride(): void
    {
        $this->authorize('update', $this->reseller);

        $this->validate([
            'csWhatsapp' => 'nullable|string|max:30',
            'csPhone' => 'nullable|string|max:30',
        ]);

        $this->reseller->update([
            'cs_whatsapp' => $this->csWhatsapp ?: null,
            'cs_phone' => $this->csPhone ?: null,
        ]);

        session()->flash('cs-status', __('Nomor CS reseller berhasil disimpan.'));
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
