<?php

namespace App\Services\Network\Provisioning\Cpe;

use App\Enums\ProvisioningAdapterLayer;
use App\Enums\SsidBand;
use App\Enums\SsidSecurity;
use App\Enums\WifiRole;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Services\Network\CtcomActivationService;
use App\Services\Network\GenieAcsClientService;
use App\Services\Network\Provisioning\AdapterCapabilities;
use App\Services\Network\Provisioning\Contracts\CpeProvisioningAdapter;
use InvalidArgumentException;
use RuntimeException;

/**
 * CPE adapter MEREK CMDC / CT-COM (TR-069) — DIDUKUNG, dipakai LINTAS semua
 * OLT (G02ID/E04ID/ZTE C300; terbukti TR-069 reachable di ketiganya, section
 * G1 docs/omci/ztp-dynamic-config-design.md).
 *
 * v0.23.8.2 — wrapper tipis di atas CtcomActivationService (sumber logika,
 * tes coverage existing-nya dipertahankan): WAN internet device-driven +
 * verifikasi WAN bridge. `pushSsid` masih skeleton (logika SSID AddObject ada
 * di default-wan.js/ad-hoc v0.23.7, belum ada method PHP untuk dipecah —
 * di-wire saat template SSID + orkestrator dibangun, v0.23.8.4/.5).
 *
 * Customer + genieacs_device_id diturunkan dari CpeDevice; `$spec` membawa
 * override opsional (mis. `requested_by`, `bridge_vlan`). Bentuk `$spec` final
 * ditetapkan saat template ZTP (v0.23.8.3/.4).
 */
final class CtcomCpeAdapter implements CpeProvisioningAdapter
{
    public function __construct(private readonly string $vendorKey = 'ctcom') {}

    public function vendorKey(): string
    {
        return $this->vendorKey;
    }

    public function layer(): ProvisioningAdapterLayer
    {
        return ProvisioningAdapterLayer::Cpe;
    }

    public function transport(): string
    {
        return 'tr069';
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities([
            'wan_internet' => true,
            'wan_bridge' => true,
            'ssid' => true,
            'verify' => true,
        ]);
    }

    public function pushWanInternet(CpeDevice $cpe, array $spec): array
    {
        return $this->service()->pushNetworkPolicy(
            $this->resolveCustomer($cpe),
            $this->resolveGenieAcsDeviceId($cpe),
            $spec['requested_by'] ?? null,
        );
    }

    public function pushWanBridge(CpeDevice $cpe, array $spec): array
    {
        // WAN bridge CT-COM itu sendiri device-driven (CPE/firmware membentuk WCD
        // bridge VID172) — adapter VERIFIKASI keberadaannya, tidak mem-push dari
        // nol (eksperimen push bridge ZTE C300 = v0.23.8.5, section G3). TAPI
        // LAN-binding-nya (`X_CT-COM_LanInterface`: WLAN instance mana yang
        // di-bridge WAN ini) BUKAN device-driven — HARUS di-set eksplisit, dan
        // dulunya HANYA di-set `default-wan.js` (preset auto-WAN v0.7.8, hardcode
        // "4,8"). Di sini kita set-nya di jalur ZTP, dengan instance di-DERIVE
        // dari yang GENUINELY ada di device (2.4g-only → cuma .4; 8-WLAN → .4,.8),
        // bukan "4,8" buta. Idempoten: `X_CT-COM_LanInterface` writable-but-not-
        // readable di TR-069 (g02id doc #24) → set TIAP run, tak andalkan read-back.
        $deviceId = $this->resolveGenieAcsDeviceId($cpe);
        $bridgeVlan = (int) ($spec['bridge_vlan'] ?? 172);
        $present = $this->service()->verifyBridgePresent($deviceId, $bridgeVlan);

        $base = ['bridge_present' => $present, 'bridge_vlan' => $bridgeVlan, 'device_driven' => true];

        $bridgePath = $this->service()->findBridgePppPath($deviceId, $bridgeVlan);
        if ($bridgePath === null) {
            return $base + [
                'lan_binding_set' => false,
                'lan_binding_instances' => [],
                'lan_binding_reason' => 'WANPPPConnection bridge tidak ditemukan di tree — LanInterface tak bisa di-set (bridge device-driven belum terbentuk / CPE belum Inform).',
            ];
        }

        $device = $this->genieacs()->findDeviceById($deviceId);
        $existing = $device !== null ? $this->existingWlanInstances($device) : [];

        // Bridge melayani WiFi guest → instance guest yang ADA (2.4g=4, 5g=8).
        $instances = [];
        foreach ([SsidBand::Band24, SsidBand::Band5] as $band) {
            $inst = $this->wlanInstanceFor(WifiRole::GuestWifi, $band);
            if (in_array($inst, $existing, true)) {
                $instances[] = $inst;
            }
        }

        if ($instances === []) {
            return $base + [
                'lan_binding_set' => false,
                'lan_binding_instances' => [],
                'lan_binding_reason' => 'Belum ada instance WLAN guest di device (mis. .4 belum di-AddObject oleh pushSsid) — binding ditunda sampai SSID guest terbentuk; orkestrator memanggil ulang.',
            ];
        }

        $csv = implode(',', array_map(
            static fn (int $i): string => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}",
            $instances,
        ));

        $this->genieacs()->sendTask($deviceId, [
            'name' => 'setParameterValues',
            'parameterValues' => [
                ["{$bridgePath}.X_CT-COM_LanInterface", $csv, 'xsd:string'],
                ["{$bridgePath}.X_CT-COM_LanInterface-DHCPEnable", false, 'xsd:boolean'],
            ],
        ], false);

        return $base + [
            'lan_binding_set' => true,
            'lan_binding_instances' => $instances,
            'lan_binding_value' => $csv,
            'bridge_path' => $bridgePath,
        ];
    }

    /**
     * SSID via TR-069 (adaptasi resep TERBUKTI Dahlia/CMDCA21C01E7 v0.23.6/.7).
     * RE-ENTRANT/idempoten (pola konvergensi preset): tiap panggilan membaca
     * tree, set param untuk instance target yang SUDAH ada, dan AddObject SATU
     * kali kalau ada target yang belum ada (grup band 2.4g 1→2→3→4, 5g 5→6→7→8;
     * device isi satu instance per AddObject). Orkestrator (v0.23.8.5) memanggil
     * ulang sampai `pending` kosong — propagasi AddObject lintas Inform.
     *
     * Peran→instance CT-COM (terbukti): primary_wifi 2.4g=1/5g=5 (SUDAH ADA, tak
     * perlu AddObject); guest_wifi 2.4g=4/5g=8 (perlu AddObject kalau belum ada).
     *
     * @param  array<int, array{wifi_role: WifiRole|string, band: SsidBand|string, ssid_name: string, security_mode: SsidSecurity|string, password?: ?string}>  $ssidSpecs
     * @return array{configured: list<int>, pending: list<int>, addobject_issued: bool}
     */
    public function pushSsid(CpeDevice $cpe, array $ssidSpecs): array
    {
        $deviceId = $this->resolveGenieAcsDeviceId($cpe);
        $device = $this->genieacs()->findDeviceById($deviceId);
        if ($device === null) {
            throw new RuntimeException("Device GenieACS {$deviceId} tidak ditemukan — CPE belum Inform.");
        }

        $existing = $this->existingWlanInstances($device);
        $configured = [];
        $pending = [];
        $needAddObject = false;

        foreach ($ssidSpecs as $spec) {
            $role = $this->asWifiRole($spec['wifi_role']);
            $band = $this->asBand($spec['band']);
            $security = $this->asSecurity($spec['security_mode']);
            $instance = $this->wlanInstanceFor($role, $band);

            if (in_array($instance, $existing, true)) {
                $this->genieacs()->sendTask($deviceId, [
                    'name' => 'setParameterValues',
                    'parameterValues' => $this->ssidParameterValues(
                        $instance, $security, (string) $spec['ssid_name'], $spec['password'] ?? null,
                    ),
                ], false);
                $configured[] = $instance;
            } else {
                $pending[] = $instance;
                $needAddObject = true;
            }
        }

        if ($needAddObject) {
            // SATU AddObject per panggilan (device isi instance berikutnya di
            // urutan band) — konvergensi aman, tanpa risiko beranak berlebih.
            $this->genieacs()->sendTask($deviceId, [
                'name' => 'addObject',
                'objectName' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
            ], false);
        }

        return ['configured' => $configured, 'pending' => $pending, 'addobject_issued' => $needAddObject];
    }

    /**
     * Matikan instance WLAN yang ADA di device tapi tak terpakai: bukan target
     * SSID profil (`$ssidSpecs` → `wlanInstanceFor`) DAN tak di-bind
     * `X_CT-COM_LanInterface` WAN mana pun. Idempoten (set Enable=false; instance
     * yang sudah false tak berubah di device). Instance terpakai TIDAK disentuh.
     *
     * Contoh #261 (4-WLAN, profil guest-only instance 4, bridge VID172 bind WLAN.4):
     * existing={1,2,3,4}, kept={4} → disable {1,2,3} (menutup WLAN.1 primary dkk).
     *
     * @param  array<int, array{wifi_role: WifiRole|string, band: SsidBand|string}>  $ssidSpecs
     * @return array{existing: list<int>, kept: list<int>, disabled: list<int>}
     */
    public function disableUnboundWlan(CpeDevice $cpe, array $ssidSpecs): array
    {
        $deviceId = $this->resolveGenieAcsDeviceId($cpe);
        $device = $this->genieacs()->findDeviceById($deviceId);
        if ($device === null) {
            throw new RuntimeException("Device GenieACS {$deviceId} tidak ditemukan — CPE belum Inform.");
        }

        $existing = $this->existingWlanInstances($device);

        $covered = [];
        foreach ($ssidSpecs as $spec) {
            $covered[] = $this->wlanInstanceFor($this->asWifiRole($spec['wifi_role']), $this->asBand($spec['band']));
        }
        $kept = array_values(array_unique(array_merge($covered, $this->boundWlanInstances($device))));

        $toDisable = array_values(array_filter(
            $existing,
            static fn (int $i): bool => ! in_array($i, $kept, true),
        ));

        if ($toDisable !== []) {
            $params = [];
            foreach ($toDisable as $i) {
                $params[] = ["InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.Enable", false, 'xsd:boolean'];
            }
            $this->genieacs()->sendTask($deviceId, [
                'name' => 'setParameterValues',
                'parameterValues' => $params,
            ], false);
        }

        return ['existing' => $existing, 'kept' => $kept, 'disabled' => $toDisable];
    }

    public function verify(CpeDevice $cpe, array $expectation = []): array
    {
        $bridgeVlan = (int) ($expectation['bridge_vlan'] ?? 172);

        return [
            'bridge_present' => $this->service()->verifyBridgePresent($this->resolveGenieAcsDeviceId($cpe), $bridgeVlan),
        ];
    }

    private function service(): CtcomActivationService
    {
        return app(CtcomActivationService::class);
    }

    private function genieacs(): GenieAcsClientService
    {
        return app(GenieAcsClientService::class);
    }

    /**
     * Peran WiFi + band → nomor instance WLANConfiguration (pola CT-COM TERBUKTI
     * Dahlia/CMDCA21C01E7): primary 2.4g=1/5g=5, guest 2.4g=4/5g=8.
     */
    private function wlanInstanceFor(WifiRole $role, SsidBand $band): int
    {
        return match ([$role, $band]) {
            [WifiRole::PrimaryWifi, SsidBand::Band24] => 1,
            [WifiRole::PrimaryWifi, SsidBand::Band5] => 5,
            [WifiRole::GuestWifi, SsidBand::Band24] => 4,
            [WifiRole::GuestWifi, SsidBand::Band5] => 8,
            default => throw new InvalidArgumentException(
                "Peran WiFi \"{$role->value}\" tidak punya instance SSID (hanya primary_wifi/guest_wifi)."
            ),
        };
    }

    /**
     * Resep setParameterValues per mode (TERBUKTI v0.23.7): open =
     * BeaconType=None + WEP Disabled + BasicEncryptionModes=None + passphrase
     * kosong (JANGAN set BasicAuthenticationMode — device tolak cwmp.9007);
     * wpa2 = SSID + passphrase (BeaconType dibiarkan default WPA/WPA2 device).
     *
     * @return array<int, array{0: string, 1: mixed, 2: string}>
     */
    private function ssidParameterValues(int $instance, SsidSecurity $security, string $ssid, ?string $password): array
    {
        $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$instance}";

        if ($security === SsidSecurity::Open) {
            return [
                ["{$base}.Enable", true, 'xsd:boolean'],
                ["{$base}.SSID", $ssid, 'xsd:string'],
                ["{$base}.BeaconType", 'None', 'xsd:string'],
                ["{$base}.WEPEncryptionLevel", 'Disabled', 'xsd:string'],
                ["{$base}.BasicEncryptionModes", 'None', 'xsd:string'],
                ["{$base}.KeyPassphrase", '', 'xsd:string'],
                ["{$base}.PreSharedKey.1.PreSharedKey", '', 'xsd:string'],
            ];
        }

        if ($password === null || $password === '') {
            throw new InvalidArgumentException("SSID wpa2 (instance {$instance}) butuh password.");
        }

        return [
            ["{$base}.Enable", true, 'xsd:boolean'],
            ["{$base}.SSID", $ssid, 'xsd:string'],
            ["{$base}.KeyPassphrase", $password, 'xsd:string'],
            ["{$base}.PreSharedKey.1.PreSharedKey", $password, 'xsd:string'],
        ];
    }

    /**
     * Nomor instance WLANConfiguration yang GENUINELY ada di tree device.
     *
     * @param  array<string, mixed>  $device
     * @return list<int>
     */
    private function existingWlanInstances(array $device): array
    {
        $wlan = $device['InternetGatewayDevice']['LANDevice'][1]['WLANConfiguration'] ?? [];
        $out = [];
        foreach ($wlan as $key => $_) {
            if (is_numeric($key)) {
                $out[] = (int) $key;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Instance WLANConfiguration yang di-bind oleh `X_CT-COM_LanInterface` WAN
     * mana pun (WANPPPConnection/WANIPConnection di semua WCD) — di-parse dari
     * referensi `...WLANConfiguration.<n>` di nilai LanInterface.
     *
     * @param  array<string, mixed>  $device
     * @return list<int>
     */
    private function boundWlanInstances(array $device): array
    {
        $wcds = $device['InternetGatewayDevice']['WANDevice'][1]['WANConnectionDevice'] ?? [];
        $out = [];
        foreach ($wcds as $wcdKey => $wcd) {
            if (! is_numeric($wcdKey) || ! is_array($wcd)) {
                continue;
            }
            foreach (['WANPPPConnection', 'WANIPConnection'] as $connType) {
                $conns = $wcd[$connType] ?? [];
                if (! is_array($conns)) {
                    continue;
                }
                foreach ($conns as $connKey => $conn) {
                    if (! is_numeric($connKey) || ! is_array($conn)) {
                        continue;
                    }
                    $lanIf = $conn['X_CT-COM_LanInterface']['_value'] ?? null;
                    if (is_string($lanIf) && $lanIf !== '' && preg_match_all('/WLANConfiguration\.(\d+)/', $lanIf, $m)) {
                        foreach ($m[1] as $n) {
                            $out[] = (int) $n;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function asWifiRole(WifiRole|string $v): WifiRole
    {
        return $v instanceof WifiRole ? $v : WifiRole::from($v);
    }

    private function asBand(SsidBand|string $v): SsidBand
    {
        return $v instanceof SsidBand ? $v : SsidBand::from($v);
    }

    private function asSecurity(SsidSecurity|string $v): SsidSecurity
    {
        return $v instanceof SsidSecurity ? $v : SsidSecurity::from($v);
    }

    private function resolveCustomer(CpeDevice $cpe): Customer
    {
        $customer = $cpe->loadMissing('customer')->customer;
        if ($customer === null) {
            throw new RuntimeException("CpeDevice #{$cpe->id} tidak punya customer tertaut — tak bisa derive kredensial WAN.");
        }

        return $customer;
    }

    private function resolveGenieAcsDeviceId(CpeDevice $cpe): string
    {
        $id = $cpe->genieacs_device_id;
        if ($id === null || $id === '') {
            throw new RuntimeException("CpeDevice #{$cpe->id} belum punya genieacs_device_id — CPE belum Inform ke GenieACS.");
        }

        return $id;
    }
}
