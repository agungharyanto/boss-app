<?php

namespace App\Livewire\Network;

use App\Models\OltDevice;
use App\Models\OltUplinkPort;
use App\Services\Network\OltUplinkService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * v0.23.5 Bagian 2 — halaman detail OLT bergaya SmartOLT (tab nav). SPRINT
 * INI: HANYA tab "Uplink" yang aktif/berfungsi (baca real-time via
 * OltUplinkService, cache on-demand). Tab lain ditampilkan sbg nav item
 * tapi disabled + "Segera hadir" (placeholder jujur, belum
 * diimplementasikan). Tab default = Uplink (satu-satunya yang hidup).
 */
class OltDeviceDetail extends Component
{
    public OltDevice $oltDevice;

    public string $activeTab = 'uplink';

    public bool $refreshing = false;

    public ?string $refreshError = null;

    public ?string $refreshNotice = null;

    /**
     * Daftar tab (meniru SmartOLT). Hanya 'uplink' enabled sprint ini.
     *
     * @return array<int, array{key:string,label:string,enabled:bool}>
     */
    public function tabs(): array
    {
        return [
            ['key' => 'details', 'label' => 'OLT details', 'enabled' => false],
            ['key' => 'cards', 'label' => 'OLT cards', 'enabled' => false],
            ['key' => 'pon', 'label' => 'PON ports', 'enabled' => false],
            ['key' => 'uplink', 'label' => 'Uplink', 'enabled' => true],
            ['key' => 'vlans', 'label' => 'VLANs', 'enabled' => false],
            ['key' => 'onu_ip_pools', 'label' => 'ONU IP Pools', 'enabled' => false],
            ['key' => 'remote_acls', 'label' => 'Remote ACLs', 'enabled' => false],
            ['key' => 'profiles', 'label' => 'Profiles', 'enabled' => false],
            ['key' => 'voip', 'label' => 'VoIP profiles', 'enabled' => false],
            ['key' => 'advanced', 'label' => 'Advanced', 'enabled' => false],
        ];
    }

    public function mount(OltDevice $oltDevice): void
    {
        $this->authorize('view', $oltDevice);
        $this->oltDevice = $oltDevice;
    }

    public function selectTab(string $key): void
    {
        // Hanya tab enabled yang bisa dibuka.
        foreach ($this->tabs() as $tab) {
            if ($tab['key'] === $key && $tab['enabled']) {
                $this->activeTab = $key;

                return;
            }
        }
    }

    /**
     * Port uplink dari cache (tidak menyentuh OLT). Di-refresh eksplisit
     * lewat refreshUplink().
     *
     * @return Collection<int, OltUplinkPort>
     */
    #[Computed]
    public function uplinkPorts(): Collection
    {
        return app(OltUplinkService::class)->cached($this->oltDevice);
    }

    public function refreshUplink(OltUplinkService $service): void
    {
        $this->refreshError = null;
        $this->refreshNotice = null;
        $this->refreshing = true;

        try {
            $ports = $service->refresh($this->oltDevice, auth()->id());
            unset($this->uplinkPorts); // invalidate computed cache
            $this->refreshNotice = "Berhasil refresh {$ports->count()} port uplink.";
        } catch (\Throwable $e) {
            $this->refreshError = $e->getMessage();
        } finally {
            $this->refreshing = false;
        }
    }

    public function render()
    {
        return view('livewire.network.olt-device-detail');
    }
}
