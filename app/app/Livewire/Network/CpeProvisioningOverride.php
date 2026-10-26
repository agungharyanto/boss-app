<?php

namespace App\Livewire\Network;

use App\Models\CpeDevice;
use App\Models\ProvisioningProfile;
use App\Services\Network\Provisioning\ProvisioningProfileResolver;
use App\Services\Network\Provisioning\ProvisioningProfileService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.23.8.6b — di halaman Detail Perangkat CPE: pilih OVERRIDE profil
 * provisioning (cpe_devices.provisioning_profile_id, boleh kosong) + tampilkan
 * hasil ProvisioningProfileResolver (profil/sumber/alasan). HANYA tampil & set
 * data — TIDAK mengeksekusi provisioning apa pun (nol push, nol adapter).
 */
class CpeProvisioningOverride extends Component
{
    use AuthorizesRequests;

    public CpeDevice $cpeDevice;

    public string $profileId = '';  // '' = tanpa override

    public ?string $notice = null;

    public function mount(CpeDevice $cpeDevice): void
    {
        $this->cpeDevice = $cpeDevice;
        $this->profileId = $cpeDevice->provisioning_profile_id !== null
            ? (string) $cpeDevice->provisioning_profile_id
            : '';
    }

    public function save(ProvisioningProfileService $service): void
    {
        $this->authorize('manage', ProvisioningProfile::class);
        $service->setCpeOverride($this->cpeDevice, $this->profileId !== '' ? (int) $this->profileId : null);
        $this->cpeDevice->refresh();
        $this->notice = __('Override profil provisioning tersimpan.');
    }

    public function render()
    {
        return view('livewire.network.cpe-provisioning-override', [
            'resolution' => app(ProvisioningProfileResolver::class)->resolveForCpe($this->cpeDevice),
            'profileOptions' => ProvisioningProfile::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => auth()->user()->can('manage', ProvisioningProfile::class),
        ]);
    }
}
