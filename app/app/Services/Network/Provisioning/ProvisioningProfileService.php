<?php

namespace App\Services\Network\Provisioning;

use App\Models\CpeDevice;
use App\Models\ProvisioningProfile;
use Illuminate\Support\Facades\DB;

/**
 * v0.23.8.6b — satu-satunya penulis ProvisioningProfile + anak-anaknya
 * (WAN Internet/Bridge/SSID) + tautan paket, dari UI (BOSS-006). NOL push ke
 * device (tak memanggil adapter/orchestrator). Password (PPPoE static & SSID
 * wpa2) KOSONG saat update = TIDAK diubah (nilai tersimpan dipertahankan).
 */
class ProvisioningProfileService
{
    /**
     * @param  array<string, mixed>  $profileData  name/slug/description/enabled
     * @param  array<string, mixed>  $wanInternet
     * @param  array<string, mixed>|null  $wanBridge  null = tak ada bridge
     * @param  array<int, array<string, mixed>>  $ssids  [{wifi_role,band,ssid_name,security_mode,password}]
     * @param  array<int, bool>  $packageLinks  [package_id => is_default]
     */
    public function create(array $profileData, array $wanInternet, ?array $wanBridge, array $ssids, array $packageLinks): ProvisioningProfile
    {
        return DB::transaction(function () use ($profileData, $wanInternet, $wanBridge, $ssids, $packageLinks): ProvisioningProfile {
            $profile = ProvisioningProfile::create($profileData);  // tenant_id auto (BelongsToTenant)
            $this->persistWanInternet($profile, $wanInternet, true);
            $this->persistWanBridge($profile, $wanBridge);
            $this->persistSsids($profile, $ssids);
            $this->syncPackages($profile, $packageLinks);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<string, mixed>  $wanInternet
     * @param  array<string, mixed>|null  $wanBridge
     * @param  array<int, array<string, mixed>>  $ssids
     * @param  array<int, bool>  $packageLinks
     */
    public function update(ProvisioningProfile $profile, array $profileData, array $wanInternet, ?array $wanBridge, array $ssids, array $packageLinks): ProvisioningProfile
    {
        return DB::transaction(function () use ($profile, $profileData, $wanInternet, $wanBridge, $ssids, $packageLinks): ProvisioningProfile {
            $profile->update($profileData);
            $this->persistWanInternet($profile, $wanInternet, false);
            $this->persistWanBridge($profile, $wanBridge);
            $this->persistSsids($profile, $ssids);
            $this->syncPackages($profile, $packageLinks);

            return $profile->refresh();
        });
    }

    /**
     * Set/override profil provisioning untuk satu CPE (cpe_devices.
     * provisioning_profile_id). null = hapus override (resolver jatuh ke
     * resolusi via paket). TIDAK memanggil adapter/orchestrator (nol push).
     */
    public function setCpeOverride(CpeDevice $cpe, ?int $profileId): void
    {
        $cpe->forceFill(['provisioning_profile_id' => $profileId])->save();
    }

    public function delete(ProvisioningProfile $profile): void
    {
        // cpe_devices.provisioning_profile_id = nullOnDelete (override CPE auto-null);
        // wan_internets/wan_bridges/ssids = cascadeOnDelete (FK). Lihat migration.
        $profile->delete();
    }

    /**
     * @param  array<int, bool>  $packageLinks  [package_id => is_default]
     */
    public function syncPackages(ProvisioningProfile $profile, array $packageLinks): void
    {
        $sync = [];
        foreach ($packageLinks as $packageId => $isDefault) {
            $sync[(int) $packageId] = ['is_default' => (bool) $isDefault];
        }
        $profile->pppPackages()->sync($sync);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistWanInternet(ProvisioningProfile $profile, array $data, bool $isCreate): void
    {
        // Password PPPoE static kosong saat UPDATE = tak diubah (pertahankan).
        if (! $isCreate
            && ($data['pppoe_password_static'] ?? '') === ''
            && $profile->wanInternet !== null) {
            unset($data['pppoe_password_static']);
        }

        $profile->wanInternet()->updateOrCreate(['provisioning_profile_id' => $profile->id], $data);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function persistWanBridge(ProvisioningProfile $profile, ?array $data): void
    {
        if ($data === null) {
            $profile->wanBridge()->delete();

            return;
        }

        $profile->wanBridge()->updateOrCreate(['provisioning_profile_id' => $profile->id], $data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ssids
     */
    private function persistSsids(ProvisioningProfile $profile, array $ssids): void
    {
        $keep = [];
        foreach ($ssids as $row) {
            $role = (string) $row['wifi_role'];
            $band = (string) $row['band'];
            $security = (string) $row['security_mode'];

            $attrs = ['ssid_name' => (string) $row['ssid_name'], 'security_mode' => $security];

            if ($security === 'open') {
                $attrs['password'] = null;  // model hook juga memaksa null; eksplisit demi jelas
            } elseif (($row['password'] ?? '') !== '') {
                $attrs['password'] = (string) $row['password'];
            }
            // else: wpa2 + password kosong → omit (pertahankan nilai tersimpan;
            // untuk baris BARU, invariant model melempar — divalidasi di komponen).

            $profile->ssids()->updateOrCreate(['wifi_role' => $role, 'band' => $band], $attrs);
            $keep[] = "{$role}|{$band}";
        }

        foreach ($profile->ssids()->get() as $ssid) {
            if (! in_array("{$ssid->wifi_role->value}|{$ssid->band->value}", $keep, true)) {
                $ssid->delete();
            }
        }
    }
}
