<?php

namespace App\Services\Network\Provisioning;

use App\Models\CpeDevice;
use App\Models\OltDevice;
use App\Models\ProvisioningProfile;
use App\Services\Network\Provisioning\Exceptions\UnsupportedAdapterOperation;
use RuntimeException;
use Throwable;

/**
 * Orkestrator ZTP (v0.23.8.5a) — merakit resolver + dua adapter (CPE/OLT) jadi
 * satu alur aktivasi. LOGIC MURNI: semua operasi device didelegasikan ke adapter
 * (di-mock di test); orkestrator TIDAK menyentuh sidecar/GenieACS langsung.
 *
 * Alur provision(): resolve profil -> pilih CpeAdapter (merek) + OltAdapter
 * (vendor) -> WAN internet (via OMCI atau TR-069-device-driven sesuai kapabilitas
 * OLT adapter) -> WAN bridge (kalau ada) -> SSID per role -> naming -> save.
 *
 * HASIL TERSTRUKTUR per-tahap (bukan satu boolean): tiap tahap punya
 * {stage, ok, result|error}, sehingga kegagalan di tengah JELAS tahap mana.
 *
 * KEJUJURAN — TIDAK idempotent/rollback-safe: kalau sebuah tahap gagal di tengah,
 * tahap-tahap SEBELUMNYA yang sudah ter-push ke device TETAP ter-push (tidak
 * di-rollback). Hasil melaporkan state apa adanya (`rollback_safe => false`).
 * Konvergensi/retry adalah tanggung jawab pemanggil (atau re-run — pushWanInternet/
 * pushSsid CT-COM memang re-entrant, tapi naming/OMCI belum dijamin idempotent di
 * sini). Verifikasi device nyata = v0.23.8.5b (di luar scope ini).
 *
 * OLT WAJIB disuplai pemanggil: cpe_devices TIDAK punya FK ke OLT, jadi orkestrator
 * tak bisa menurunkannya dari $cpe sendiri (pola sama CtcomActivationService::
 * activate v0.23.6 yang juga menerima OltDevice eksplisit). $options membawa
 * input OMCI yang tak ada di profil (onu_id/onu_type/profil tcont/vlan mgmt+bridge)
 * — relevan hanya untuk OLT ber-WAN-OMCI (ZTE C300), dilengkapi di v0.23.8.5b.
 */
class ZtpOrchestratorService
{
    public function __construct(
        private readonly ProvisioningProfileResolver $resolver,
        private readonly CpeAdapterRegistry $cpeRegistry,
        private readonly OltAdapterRegistry $oltRegistry,
        private readonly SsidNameResolver $ssidNameResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $options  requested_by + (OMCI) onu_id/onu_type/
     *                                         tcont_profile/traffic_profile/vlan_mgmt/
     *                                         vlan_bridge/pon_interface.
     * @return array<string, mixed>
     */
    public function provision(CpeDevice $cpe, OltDevice $olt, array $options = []): array
    {
        // 1. Resolve profil — berhenti dgn alasan RESOLVER (bukan generic error).
        $resolution = $this->resolver->resolveForCpe($cpe);
        if (! $resolution->resolved()) {
            return [
                'ok' => false,
                'stage' => 'resolve_profile',
                'source' => $resolution->source,
                'reason' => $resolution->reason,
                'stages' => [],
            ];
        }
        $profile = $resolution->profile;

        // 2. Pilih adapter (registry melempar UnsupportedAdapterOperation kalau
        //    merek/vendor tak didukung — ditangkap sbg kegagalan tahap 'select_adapter').
        try {
            $cpeAdapter = $this->cpeRegistry->for($this->cpeBrandKey($cpe));
            $oltAdapter = $this->oltRegistry->for($olt->sidecarVendorKey());
        } catch (UnsupportedAdapterOperation|RuntimeException $e) {
            return [
                'ok' => false,
                'stage' => 'select_adapter',
                'reason' => $e->getMessage(),
                'profile' => $this->profileSummary($profile, $resolution->source),
                'stages' => [],
            ];
        }

        $customer = $cpe->loadMissing('customer')->customer;
        $requestedBy = $options['requested_by'] ?? null;
        $stages = [];

        // 3a. WAN internet — rute sesuai kapabilitas OLT adapter.
        if ($profile->wanInternet !== null) {
            $via = $oltAdapter->capabilities()->wanInternetVia;
            $ok = $this->runStage($stages, 'wan_internet', ['via' => $via], function () use ($via, $cpeAdapter, $oltAdapter, $cpe, $olt, $profile, $customer, $options, $requestedBy) {
                if ($via === 'omci') {
                    return $oltAdapter->pushWanInternetOmci($olt, $this->buildOmciWanSpec($profile, $customer, $cpe, $options));
                }

                // tr069_device_driven (CT-COM) — CPE adapter (derive kredensial dari customer/paket).
                return $cpeAdapter->pushWanInternet($cpe, ['requested_by' => $requestedBy]);
            });
            if (! $ok) {
                return $this->fail($stages, 'wan_internet', $profile, $resolution->source);
            }
        }

        // 3b. WAN bridge (kalau profil punya) — device-driven CPE.
        if ($profile->wanBridge !== null) {
            $ok = $this->runStage($stages, 'wan_bridge', [], fn () => $cpeAdapter->pushWanBridge($cpe, [
                'bridge_vlan' => $profile->wanBridge->vlan,
                'requested_by' => $requestedBy,
            ]));
            if (! $ok) {
                return $this->fail($stages, 'wan_bridge', $profile, $resolution->source);
            }
        }

        // 3c. SSID per role (resolve placeholder nama via customer).
        $ssidSpecs = $this->buildSsidSpecs($profile, $customer);
        if ($ssidSpecs !== []) {
            $ok = $this->runStage($stages, 'ssid', ['count' => count($ssidSpecs)], fn () => $cpeAdapter->pushSsid($cpe, $ssidSpecs));
            if (! $ok) {
                return $this->fail($stages, 'ssid', $profile, $resolution->source);
            }
        }

        // 3d. Naming (OLT) + 3e. Save (OLT).
        $ok = $this->runStage($stages, 'naming', [], fn () => $oltAdapter->applyNaming($olt, [
            'customer' => $customer,
            'sn' => (string) $cpe->serial_number,
            'requested_by' => $requestedBy,
        ]));
        if (! $ok) {
            return $this->fail($stages, 'naming', $profile, $resolution->source);
        }

        $ok = $this->runStage($stages, 'save', [], fn () => $oltAdapter->saveConfig($olt));
        if (! $ok) {
            return $this->fail($stages, 'save', $profile, $resolution->source);
        }

        return [
            'ok' => true,
            'profile' => $this->profileSummary($profile, $resolution->source),
            'stages' => $stages,
            'rollback_safe' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<string, mixed>  $meta
     */
    private function runStage(array &$stages, string $name, array $meta, callable $fn): bool
    {
        try {
            $result = $fn();
            $stages[] = array_merge(['stage' => $name, 'ok' => true, 'result' => $result], $meta);

            return true;
        } catch (Throwable $e) {
            $stages[] = array_merge(['stage' => $name, 'ok' => false, 'error' => $e->getMessage()], $meta);

            return false;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<string, mixed>
     */
    private function fail(array $stages, string $failedStage, ProvisioningProfile $profile, string $source): array
    {
        return [
            'ok' => false,
            'failed_stage' => $failedStage,
            'profile' => $this->profileSummary($profile, $source),
            'stages' => $stages,
            // JUJUR: tahap sebelum $failedStage yang sudah sukses TIDAK di-rollback.
            'rollback_safe' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSummary(ProvisioningProfile $profile, string $source): array
    {
        return ['id' => $profile->id, 'slug' => $profile->slug, 'resolved_via' => $source];
    }

    /**
     * Merek CPE (untuk CpeAdapterRegistry) dari cpe_devices.manufacturer. FIRST-CUT
     * berbasis string manufacturer — deteksi merek yang lebih andal (via data-model
     * TR-069 seperti default-wan.js) butuh baca device, disempurnakan kemudian.
     * CMDC = jalur TERBUKTI. Merek tak dikenal -> registry melempar (ditangani tahap).
     */
    private function cpeBrandKey(CpeDevice $cpe): string
    {
        $m = strtoupper((string) $cpe->manufacturer);

        return match (true) {
            str_contains($m, 'CMDC'), str_contains($m, 'CT-COM'), str_contains($m, 'CTCOM') => 'ctcom',
            str_contains($m, 'HUAWEI') => 'huawei',
            str_contains($m, 'CMCC') => 'cmcc',
            str_contains($m, 'ZTE') => 'zte_generic',
            default => throw new UnsupportedAdapterOperation(
                "Merek CPE tak dikenal dari manufacturer \"{$cpe->manufacturer}\" (CpeDevice #{$cpe->id})."
            ),
        };
    }

    /**
     * Spec activate_onu (OMCI, ZTE) — gabung profil (vlan_pppoe) + customer (name)
     * + cpe (sn) + $options (onu_id/onu_type/tcont/traffic/vlan_mgmt/vlan_bridge,
     * disuplai pemanggil v0.23.8.5b). Field kurang -> ZteC300OltAdapter sendiri
     * yang menolak jelas saat push.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildOmciWanSpec(ProvisioningProfile $profile, mixed $customer, CpeDevice $cpe, array $options): array
    {
        return array_filter([
            'pon_interface' => $options['pon_interface'] ?? null,
            'onu_id' => $options['onu_id'] ?? null,
            'sn' => (string) $cpe->serial_number,
            'onu_type' => $options['onu_type'] ?? null,
            'name' => $customer !== null ? trim(((string) $customer->name).' - '.((string) $customer->cid)) : null,
            'tcont_profile' => $options['tcont_profile'] ?? null,
            'traffic_profile' => $options['traffic_profile'] ?? null,
            'vlan_pppoe' => $profile->wanInternet?->vlan,
            'vlan_mgmt' => $options['vlan_mgmt'] ?? null,
            'vlan_bridge' => $options['vlan_bridge'] ?? null,
            'requested_by' => $options['requested_by'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Bangun spec SSID per role dari profil, nama di-resolve placeholder-nya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSsidSpecs(ProvisioningProfile $profile, mixed $customer): array
    {
        $specs = [];
        foreach ($profile->ssids as $ssid) {
            $specs[] = [
                'wifi_role' => $ssid->wifi_role,
                'band' => $ssid->band,
                'ssid_name' => $customer !== null
                    ? $this->ssidNameResolver->resolve((string) $ssid->ssid_name, $customer)
                    : (string) $ssid->ssid_name,
                'security_mode' => $ssid->security_mode,
                'password' => $ssid->password,
            ];
        }

        return $specs;
    }
}
