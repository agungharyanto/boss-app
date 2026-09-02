<?php

namespace App\Services\Network;

use App\Models\CpeDeviceModelCapability;
use App\Models\CpeParameterMap;
use Illuminate\Support\Collection;

/**
 * Ties CpeParameterMap (which path, which formula) to a real GenieACS
 * device (which raw value is actually sitting there right now) — the
 * matching key is `_deviceId._OUI`/`_deviceId._ProductClass`, read straight
 * from GenieAcsClientService's response, never parsed back out of the
 * percent-encoded `_id` string (avoids re-deriving encoding rules GenieACS
 * itself already applied once).
 */
class CpeParameterResolverService
{
    public function __construct(
        private readonly GenieAcsClientService $genieAcsClient,
        private readonly ParameterConversionService $conversionService,
    ) {}

    /**
     * @return array<string, array{
     *     parameter_key: string,
     *     parameter_path: string,
     *     raw_value: mixed,
     *     value: ?float,
     *     verified: bool,
     *     error: ?string,
     * }>
     */
    public function resolveForDevice(string $genieAcsDeviceId): array
    {
        $device = $this->genieAcsClient->findDeviceById($genieAcsDeviceId);

        if ($device === null) {
            return [];
        }

        $oui = $device['_deviceId']['_OUI'] ?? null;
        $productClass = $device['_deviceId']['_ProductClass'] ?? null;

        if ($oui === null || $productClass === null) {
            return [];
        }

        $maps = $this->mapsFor($oui, $productClass);

        $resolved = [];

        foreach ($maps as $map) {
            $resolved[$map->parameter_key] = $this->resolveOne($device, $map);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array{
     *     parameter_key: string,
     *     parameter_path: string,
     *     raw_value: mixed,
     *     value: ?float,
     *     verified: bool,
     *     error: ?string,
     * }
     */
    private function resolveOne(array $device, CpeParameterMap $map): array
    {
        $rawValue = $this->extractPath($device, $map->parameter_path);

        $result = [
            'parameter_key' => $map->parameter_key,
            'parameter_path' => $map->parameter_path,
            'raw_value' => $rawValue,
            'value' => null,
            'verified' => $map->isVerified(),
            'error' => null,
        ];

        if ($rawValue === null) {
            $result['error'] = 'Path not present in this device\'s parameter tree — may need a refreshObject task first.';

            return $result;
        }

        try {
            $result['value'] = $this->conversionService->convert(
                $rawValue,
                $map->conversion_formula,
                $map->conversion_params ?? [],
            );
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private const MAC_PARAMETER_KEY = 'mac_address';

    private const RX_PARAMETER_KEY = 'rx_power_dbm';

    private const TX_PARAMETER_KEY = 'tx_power_dbm';

    private const UPTIME_PARAMETER_KEY = 'device_uptime_seconds';

    /**
     * MAC address via WANPPPConnection — pattern confirmed by a colleague's
     * own working GenieACS config ("pppoeMac", 2026-08-16). Fixed, standard
     * TR-069 object, not vendor-specific (unlike rx_power_dbm/tx_power_dbm),
     * so this deliberately isn't a per-OUI `cpe_parameter_maps` row — a
     * catalog row only ever holds one fixed path per key. Supersedes 3
     * earlier candidate paths (X_CU_SerialNumber,
     * LANHostConfigManagement.MACAddress, a WANDevice-level MAC) that were
     * tried and abandoned in an earlier session — those never resolved on
     * any real device in this fleet.
     *
     * The referral's own known indices were WANConnectionDevice/
     * WANPPPConnection 1/2 x 1/2 — but a real device in THIS fleet
     * (0815AE-H3-2s-CMDCA223E8A5) turned out to use WANConnectionDevice
     * 2/3/4 instead (no `.1` at all), so resolveMacFallback() walks
     * whatever indices the device's own tree actually has (populated by the
     * Bagian C wildcard `path:` declare in default.js) rather than a fixed
     * 4-path list — the fixed list from the referral would have silently
     * missed this device's real MAC entirely.
     */
    private const MAC_ALL_ZERO_PLACEHOLDER = '00:00:00:00:00:00';

    /**
     * Objek optik yang MELAPORKAN NILAI SUDAH DALAM dBm (langsung pakai raw).
     * Sumber: pola VirtualParameters GenieACS rekan Agung
     * (`virtualParameters-2026-09-02...csv`) — Huawei/FiberHome/Nokia.
     */
    private const OPTICAL_DIRECT_OBJECTS = [
        'X_GponInterafceConfig',     // Huawei (ejaan "Interafce" apa adanya)
        'X_FH_GponInterfaceConfig',  // FiberHome
    ];

    /**
     * Objek optik dengan RAW SFF-8472 (perlu 10*log10(raw * scale)). Sebagian
     * firmware ZTE justru sudah melaporkan dBm di objek yang sama — ditandai
     * oleh nilai NEGATIF, dalam hal itu raw dipakai langsung (persis logika
     * VP `RXPower`: `if (zteval < 0) m = zteval`).
     */
    private const OPTICAL_SFF8472_OBJECTS = [
        'X_CT-COM_GponInterfaceConfig',
        'X_CT-COM_EponInterfaceConfig',
        'X_CMCC_GponInterfaceConfig',
        'X_CMCC_EponInterfaceConfig',
        'X_ZTE-COM_WANPONInterfaceConfig',
        'X_CU_WANEPONInterfaceConfig.OpticalTransceiver',
        'X_CU_WANGPONInterfaceConfig.OpticalTransceiver',
    ];

    private const OPTICAL_SFF8472_SCALE = 0.0001;

    /**
     * `cpe_parameter_maps` yang cocok untuk (OUI, ProductClass) — dengan
     * FALLBACK ke product_class saja kalau tidak ada baris untuk OUI persis
     * itu (2026-09-02). Path/formula optik ditentukan oleh MODEL, bukan OUI
     * (OUI cuma prefiks MAC pabrikan; model yang sama bisa dikirim dgn
     * beberapa batch OUI). Fleet nyata: `M63X-XPON` / `GM220-S` / `H3-2s`
     * masing-masing punya 5-15 OUI berbeda, `cpe_parameter_maps` hanya
     * meng-cover sebagian → tanpa fallback ini, RX/TX kosong untuk mayoritas
     * perangkat padahal datanya ADA di pohon.
     *
     * @return Collection<int, CpeParameterMap>
     */
    private function mapsFor(?string $oui, ?string $productClass)
    {
        if ($productClass === null) {
            return collect();
        }

        $exact = CpeParameterMap::query()
            ->where('oui', $oui)
            ->where('product_class', $productClass)
            ->get();

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        // Satu baris per parameter_key (OUI mana pun) — path/formula per model
        // konsisten di fleet ini.
        return CpeParameterMap::query()
            ->where('product_class', $productClass)
            ->get()
            ->unique('parameter_key')
            ->values();
    }

    /**
     * The one place MAC/RX/TX/uptime get pulled into the fixed shape every UI
     * surface actually wants — was originally duplicated inline in
     * App\Livewire\Network\CpeDeviceIndex.
     *
     * 2026-09-02 (fill CPE data): tiap nilai punya RANTAI fallback, bukan
     * hanya `cpe_parameter_maps` exact-OUI:
     *   1. baris map (exact OUI+PC → PC-only, via mapsFor()),
     *   2. penelusuran objek vendor generik (RX/TX: OPTICAL_*_OBJECTS;
     *      MAC: WANPPPConnection/WANIPConnection/LAN; uptime: DeviceInfo.UpTime).
     * Pola & daftar path diambil dari VirtualParameters GenieACS rekan Agung
     * sebagai REFERENSI — logikanya diport ke resolver PHP ini, BUKAN
     * dijadikan VirtualParameter GenieACS (lihat docs/genieacs-virtual-parameters-evaluation.md).
     *
     * Never throws — semua kegagalan → field null.
     *
     * @return array{mac_address: ?string, rx_power_dbm: ?float, tx_power_dbm: ?float, device_uptime_seconds: ?float}
     */
    public function resolveDeviceSummary(?string $genieAcsDeviceId): array
    {
        $empty = ['mac_address' => null, 'rx_power_dbm' => null, 'tx_power_dbm' => null, 'device_uptime_seconds' => null];

        $device = $this->safeFindDevice($genieAcsDeviceId);

        if ($device === null) {
            return $empty;
        }

        $oui = $device['_deviceId']['_OUI'] ?? null;
        $productClass = $device['_deviceId']['_ProductClass'] ?? null;
        $maps = $this->mapsFor($oui, $productClass)->keyBy('parameter_key');

        return [
            'mac_address' => $this->rawFromMap($device, $maps->get(self::MAC_PARAMETER_KEY))
                ?? $this->resolveMacFromDevice($device),
            'rx_power_dbm' => $this->valueFromMap($device, $maps->get(self::RX_PARAMETER_KEY))
                ?? $this->resolveOpticalDbm($device, 'RXPower'),
            'tx_power_dbm' => $this->valueFromMap($device, $maps->get(self::TX_PARAMETER_KEY))
                ?? $this->resolveOpticalDbm($device, 'TXPower'),
            'device_uptime_seconds' => $this->valueFromMap($device, $maps->get(self::UPTIME_PARAMETER_KEY))
                ?? $this->resolveUptimeSecondsFromDevice($device),
        ];
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function valueFromMap(array $device, ?CpeParameterMap $map): ?float
    {
        if ($map === null) {
            return null;
        }

        $result = $this->resolveOne($device, $map);

        return isset($result['value']) && is_numeric($result['value']) ? (float) $result['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function rawFromMap(array $device, ?CpeParameterMap $map): ?string
    {
        if ($map === null) {
            return null;
        }

        $raw = $this->resolveOne($device, $map)['raw_value'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * RX/TX optik dBm dari objek vendor apa pun yang ada di pohon perangkat
     * — mirror logika VP `RXPower`: objek "direct" (Huawei/FiberHome/Nokia)
     * dipakai apa adanya; objek SFF-8472 (CT-COM/CMCC/ZTE-COM/CU) → raw
     * NEGATIF berarti sudah dBm (pakai langsung), raw POSITIF → 10*log10(raw
     * * 0.0001), raw 0 = tidak ada sinyal (lewati, coba objek berikutnya).
     *
     * @param  array<string, mixed>  $device
     * @param  'RXPower'|'TXPower'  $leaf
     */
    private function resolveOpticalDbm(array $device, string $leaf): ?float
    {
        $wanDevices = $device['InternetGatewayDevice']['WANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($wanDevices) as $wanKey) {
            $base = "InternetGatewayDevice.WANDevice.{$wanKey}.";

            foreach (self::OPTICAL_DIRECT_OBJECTS as $obj) {
                $raw = $this->extractPath($device, $base.$obj.'.'.$leaf);
                if (is_numeric($raw)) {
                    return (float) $raw;
                }
            }

            foreach (self::OPTICAL_SFF8472_OBJECTS as $obj) {
                $raw = $this->extractPath($device, $base.$obj.'.'.$leaf);
                if (! is_numeric($raw)) {
                    continue;
                }
                $raw = (float) $raw;
                if ($raw < 0) {
                    return $raw; // firmware sudah melaporkan dBm
                }
                if ($raw > 0) {
                    return 10 * log10($raw * self::OPTICAL_SFF8472_SCALE);
                }
                // $raw === 0.0 → tidak ada sinyal di objek ini, coba objek lain
            }
        }

        // Nokia/ALU — langsung di bawah InternetGatewayDevice, bukan WANDevice
        $nokia = $this->extractPath($device, "InternetGatewayDevice.X_ALU_OntOpticalParam.{$leaf}");

        return is_numeric($nokia) ? (float) $nokia : null;
    }

    /**
     * Uptime detik dari `DeviceInfo.UpTime` (TR-098) atau `Device.DeviceInfo.
     * UpTime` (TR-181 / MikroTik) — path standar, bukan per-vendor.
     *
     * @param  array<string, mixed>  $device
     */
    private function resolveUptimeSecondsFromDevice(array $device): ?float
    {
        foreach (['InternetGatewayDevice.DeviceInfo.UpTime', 'Device.DeviceInfo.UpTime'] as $path) {
            $raw = $this->extractPath($device, $path);
            if (is_numeric($raw)) {
                return (float) $raw;
            }
        }

        return null;
    }

    /**
     * MAC perangkat dari pohon TR-069 yang SUDAH di-fetch — rantai kandidat
     * (2026-09-02, mengikuti VP `PonMac`/`pppoeMac` rekan Agung sebagai
     * referensi):
     *   1. WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.MACAddress
     *   2. WANDevice.*.WANConnectionDevice.*.WANIPConnection.*.MACAddress
     *   3. LANDevice.*.LANEthernetInterfaceConfig.*.MACAddress
     *   4. LANDevice.1.LANHostConfigManagement.MACAddress
     *   5. DeviceInfo.X_CU_SerialNumber (sebagian ZTE lama = MAC di sini)
     * Objek TR-069 standar (bukan per-vendor) → sengaja bukan baris
     * `cpe_parameter_maps`. MAC all-zero diperlakukan sebagai "tidak ada".
     *
     * @param  array<string, mixed>  $device
     */
    private function resolveMacFromDevice(array $device): ?string
    {
        $wanDevices = $device['InternetGatewayDevice']['WANDevice'] ?? [];

        foreach (['WANPPPConnection', 'WANIPConnection'] as $connType) {
            foreach ($this->sortedInstanceKeys($wanDevices) as $wanKey) {
                $connectionDevices = $wanDevices[$wanKey]['WANConnectionDevice'] ?? [];

                foreach ($this->sortedInstanceKeys($connectionDevices) as $cdKey) {
                    $conns = $connectionDevices[$cdKey][$connType] ?? [];

                    foreach ($this->sortedInstanceKeys($conns) as $connKey) {
                        $mac = $conns[$connKey]['MACAddress']['_value'] ?? null;

                        if ($this->isUsableMac($mac)) {
                            return $mac;
                        }
                    }
                }
            }
        }

        $lanDevices = $device['InternetGatewayDevice']['LANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($lanDevices) as $lanKey) {
            $ifaces = $lanDevices[$lanKey]['LANEthernetInterfaceConfig'] ?? [];

            foreach ($this->sortedInstanceKeys($ifaces) as $ifKey) {
                $mac = $ifaces[$ifKey]['MACAddress']['_value'] ?? null;

                if ($this->isUsableMac($mac)) {
                    return $mac;
                }
            }

            $mac = $lanDevices[$lanKey]['LANHostConfigManagement']['MACAddress']['_value'] ?? null;

            if ($this->isUsableMac($mac)) {
                return $mac;
            }
        }

        $mac = $this->extractPath($device, 'InternetGatewayDevice.DeviceInfo.X_CU_SerialNumber');

        return $this->isUsableMac($mac) ? $mac : null;
    }

    /**
     * PHP silently casts purely-numeric string array keys ("1", "2", ...)
     * to real ints, so a plain `is_string($key)` filter here would drop
     * every genuine TR-069 instance key and keep only the "_object"/
     * "_writable"/etc. metadata keys — exactly backwards. Only a STRING key
     * can ever start with "_" (an int key never does), so filtering on
     * that directly, without an is_string() guard, is correct for both key
     * types.
     *
     * @param  array<int|string, mixed>  $node
     * @return array<int, int|string>
     */
    private function sortedInstanceKeys(array $node): array
    {
        $keys = array_values(array_filter(
            array_keys($node),
            static fn ($key) => ! (is_string($key) && $key !== '' && $key[0] === '_'),
        ));

        sort($keys, SORT_NATURAL);

        return $keys;
    }

    /**
     * A TR-069 device commonly reports an all-zero MAC on an unused/unbound
     * WANPPPConnection slot as a placeholder, not a genuine value — found
     * for real on 0815AE-H3-2s-CMDCA223E8A5's WANConnectionDevice.2, sitting
     * right next to a sibling instance (.3) with a genuine, different MAC.
     * Treated the same as "no value" rather than a resolved MAC.
     */
    private function isUsableMac(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strtoupper($value) !== self::MAC_ALL_ZERO_PLACEHOLDER;
    }

    /**
     * Walks a dot-separated TR-069 path into GenieACS's own nested
     * `{"_value": ...}` leaf shape (see GenieAcsClientService's own
     * docblock for the confirmed response format).
     *
     * @param  array<string, mixed>  $device
     */
    private function extractPath(array $device, string $path): mixed
    {
        $node = $device;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        if (is_array($node) && array_key_exists('_value', $node)) {
            return $node['_value'];
        }

        return null;
    }

    /**
     * "Attached VLANs" for the CPE detail page (2026-08-16) — every
     * WANPPPConnection/WANIPConnection instance in the device's own tree
     * with a real (non-empty) `Name` — GenieACS's stock `Name` field
     * already encodes the VLAN (e.g. `1_INTERNET_R_VID_131`), confirmed
     * for real on A4F33B-GM219-ZICG298BF2F9's WCD.3.WANPPPConnection.1.
     * Only WHICH indices exist needed our own `path:` discovery (Bagian C,
     * 2026-08-16 preset) — GenieACS already walks a discovered instance's
     * own leaves (Name/ConnectionStatus/Enable/TransportType/...)
     * automatically, no extra `value:` declare was needed for these.
     *
     * @return array<int, array{name: string, type: string, status: ?string}>
     */
    public function resolveWanConnectionsSummary(?string $genieAcsDeviceId): array
    {
        $device = $this->safeFindDevice($genieAcsDeviceId);

        if ($device === null) {
            return [];
        }

        $connections = [];
        $wanDevices = $device['InternetGatewayDevice']['WANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($wanDevices) as $wanKey) {
            $connectionDevices = $wanDevices[$wanKey]['WANConnectionDevice'] ?? [];

            foreach ($this->sortedInstanceKeys($connectionDevices) as $cdKey) {
                $cd = $connectionDevices[$cdKey];

                foreach ($this->sortedInstanceKeys($cd['WANPPPConnection'] ?? []) as $pppKey) {
                    $conn = $cd['WANPPPConnection'][$pppKey];
                    $name = $conn['Name']['_value'] ?? null;

                    if (is_string($name) && $name !== '') {
                        $connections[] = ['name' => $name, 'type' => 'PPPoE', 'status' => $conn['ConnectionStatus']['_value'] ?? null];
                    }
                }

                foreach ($this->sortedInstanceKeys($cd['WANIPConnection'] ?? []) as $ipKey) {
                    $conn = $cd['WANIPConnection'][$ipKey];
                    $name = $conn['Name']['_value'] ?? null;

                    if (is_string($name) && $name !== '') {
                        $connections[] = ['name' => $name, 'type' => 'IP', 'status' => $conn['ConnectionStatus']['_value'] ?? null];
                    }
                }
            }
        }

        return $connections;
    }

    /**
     * The real PPPoE connection's Username/Password/Name (2026-08-16) — the
     * first WANPPPConnection instance with a non-empty `Username`, same
     * "first real one wins" heuristic as resolveMacFallback(), but keyed on
     * Username presence rather than MACAddress (a device commonly has
     * several WANPPPConnection instances — only one is the customer's real
     * internet connection). Deliberately a SEPARATE call from
     * resolveWanConnectionsSummary() above, even though both walk the same
     * object tree — callers that only need the read-many-times username
     * (main detail page) should never also fetch the password in the same
     * response; the password is only ever returned to the dedicated
     * on-demand reveal endpoint. `password` reads back empty on real
     * hardware as often as WiFi's does (same vendor behavior documented in
     * CLAUDE.md's GenieACS Remote Actions section) — not a bug, not a sign
     * this method picked the wrong instance.
     *
     * @return ?array{name: ?string, username: string, password: ?string}
     */
    public function resolvePppoeConnection(?string $genieAcsDeviceId): ?array
    {
        $device = $this->safeFindDevice($genieAcsDeviceId);

        if ($device === null) {
            return null;
        }

        $wanDevices = $device['InternetGatewayDevice']['WANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($wanDevices) as $wanKey) {
            $connectionDevices = $wanDevices[$wanKey]['WANConnectionDevice'] ?? [];

            foreach ($this->sortedInstanceKeys($connectionDevices) as $cdKey) {
                $pppConnections = $connectionDevices[$cdKey]['WANPPPConnection'] ?? [];

                foreach ($this->sortedInstanceKeys($pppConnections) as $pppKey) {
                    $conn = $pppConnections[$pppKey];

                    if ($this->isBridgedConnection($conn)) {
                        continue; // WAN bridged — Username/IP-nya milik router di belakang ONT, tidak berarti
                    }

                    $username = $conn['Username']['_value'] ?? null;

                    if (is_string($username) && $username !== '') {
                        return [
                            'name' => $conn['Name']['_value'] ?? null,
                            'username' => $username,
                            'password' => $conn['Password']['_value'] ?? null,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Koneksi WAN dalam mode bridge — `ConnectionType` == `PPPoE_Bridged`
     * atau `bridge` (dari VP `pppoeUsername2`/`pppoeIP` rekan Agung).
     * Username/ExternalIPAddress-nya bukan milik pelanggan (dial PPPoE ada
     * di router di belakang ONT), jadi jangan disurfacekan.
     *
     * @param  array<string, mixed>  $conn
     */
    private function isBridgedConnection(array $conn): bool
    {
        $type = strtolower((string) ($conn['ConnectionType']['_value'] ?? ''));

        return in_array($type, ['pppoe_bridged', 'bridge', 'ip_bridged'], true);
    }

    /**
     * Every WLANConfiguration instance actually present (2026-08-16) — for
     * the detail page's WiFi/SSID table, showing every real SSID (not just
     * `.1`) with its Enable status. Relies on the same wildcard `path:`
     * declare (default.js) that surfaced `.4`/`.5` fleet-wide; an instance
     * with no SSID leaf at all (container discovered but not yet synced)
     * is skipped rather than shown as a blank row.
     *
     * @return array<int, array{index: string, ssid: string, enabled: ?bool}>
     */
    /**
     * Default when a device's OUI+ProductClass has no
     * `cpe_device_model_capabilities` row (never empirically confirmed to
     * go beyond this) — see CpeDeviceModelCapabilitySeeder's own docblock
     * for why "haven't confirmed more than 1" deliberately still defaults
     * to 4 rather than 1: a combo that's only ever shown index 1 so far
     * might just not have synced further yet (the same path/value
     * convergence lag already documented for MAC/PPPoE/AssociatedDevice
     * elsewhere in this codebase), and 4 keeps those slots visible-but-
     * empty instead of permanently hiding them.
     */
    private const DEFAULT_MAX_SSID_SLOTS = 4;

    /**
     * Every SSID slot 1..N this device's model is known (or, absent a
     * catalog row, assumed) to have — 2026-08-19, so the CPE detail page
     * can render a genuinely empty/"Nonaktif" placeholder row for a slot
     * with no real data yet, instead of silently omitting it (previously:
     * a device confirmed to have SSID.1 and SSID.5 only ever showed 2
     * rows, with no visual indication that .2/.3/.4 might exist at all).
     * Real data still wins wherever it exists; a real index found BEYOND
     * the catalog's own max_ssid_slots is still shown too, never dropped
     * just because it exceeds what the capability row currently claims —
     * that's a live vendor firmware fact overriding a possibly-stale
     * catalog guess.
     *
     * Returns [] (not N empty placeholders) when this device has ZERO
     * real WLANConfiguration data discovered yet at all — that's the same
     * "not discovered/not connected" case the UI already renders as "-",
     * distinct from "discovered some slots, missing others" which is what
     * this method's padding is actually for.
     *
     * @return array<int, array{index: string, ssid: ?string, enabled: ?bool}>
     */
    public function resolveWlanConfigurations(?string $genieAcsDeviceId): array
    {
        $device = $this->safeFindDevice($genieAcsDeviceId);

        if ($device === null) {
            return [];
        }

        $realByIndex = [];
        $lanDevices = $device['InternetGatewayDevice']['LANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($lanDevices) as $lanKey) {
            $wlanConfigs = $lanDevices[$lanKey]['WLANConfiguration'] ?? [];

            foreach ($this->sortedInstanceKeys($wlanConfigs) as $wlanKey) {
                $ssid = $wlanConfigs[$wlanKey]['SSID']['_value'] ?? null;

                if (! is_string($ssid) || $ssid === '') {
                    continue;
                }

                $realByIndex[(int) $wlanKey] = [
                    'index' => (string) $wlanKey,
                    'ssid' => $ssid,
                    'enabled' => $wlanConfigs[$wlanKey]['Enable']['_value'] ?? null,
                ];
            }
        }

        if ($realByIndex === []) {
            return [];
        }

        $maxSlots = $this->maxSsidSlotsFor($device);

        $result = [];

        for ($index = 1; $index <= $maxSlots; $index++) {
            $result[] = $realByIndex[$index] ?? [
                'index' => (string) $index,
                'ssid' => null,
                'enabled' => null,
            ];
        }

        foreach ($realByIndex as $index => $row) {
            if ($index > $maxSlots) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function maxSsidSlotsFor(array $device): int
    {
        $oui = $device['_deviceId']['_OUI'] ?? null;
        $productClass = $device['_deviceId']['_ProductClass'] ?? null;

        if ($oui === null || $productClass === null) {
            return self::DEFAULT_MAX_SSID_SLOTS;
        }

        $maxSlots = CpeDeviceModelCapability::query()
            ->where('oui', $oui)
            ->where('product_class', $productClass)
            ->value('max_ssid_slots');

        return $maxSlots ?? self::DEFAULT_MAX_SSID_SLOTS;
    }

    /**
     * Ethernet ports (2026-08-16) — LANEthernetInterfaceConfig, present on
     * SOME devices (confirmed for real on a Huawei EG8141A5) but not
     * declared/discovered by anything in default.js yet, so this only ever
     * reflects whatever GenieACS happened to capture on its own. Returns an
     * empty array (never a placeholder row) when the device has none —
     * the detail page hides the whole section in that case rather than
     * showing an empty table.
     *
     * @return array<int, array{name: string, enabled: ?bool, status: ?string, mac_address: ?string}>
     */
    public function resolveEthernetPorts(?string $genieAcsDeviceId): array
    {
        $device = $this->safeFindDevice($genieAcsDeviceId);

        if ($device === null) {
            return [];
        }

        $result = [];
        $lanDevices = $device['InternetGatewayDevice']['LANDevice'] ?? [];

        foreach ($this->sortedInstanceKeys($lanDevices) as $lanKey) {
            $ports = $lanDevices[$lanKey]['LANEthernetInterfaceConfig'] ?? [];

            foreach ($this->sortedInstanceKeys($ports) as $portKey) {
                $port = $ports[$portKey];
                $name = $port['Name']['_value'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $result[] = [
                    'name' => $name,
                    'enabled' => $port['Enable']['_value'] ?? null,
                    'status' => $port['Status']['_value'] ?? null,
                    'mac_address' => $port['MACAddress']['_value'] ?? null,
                ];
            }
        }

        return $result;
    }

    private function safeFindDevice(?string $genieAcsDeviceId): ?array
    {
        if ($genieAcsDeviceId === null) {
            return null;
        }

        try {
            return $this->genieAcsClient->findDeviceById($genieAcsDeviceId);
        } catch (\Throwable) {
            return null;
        }
    }
}
