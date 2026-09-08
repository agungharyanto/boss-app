<?php

namespace App\Services\Network;

use App\Models\CpeDevice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Device GenieACS yang sudah check-in tapi belum punya baris cpe_devices
 * sama sekali" — sumber data untuk section "Belum Ter-bind" di
 * /cpe-devices.
 *
 * Alur bind normal: device Inform ke genieacs-cwmp -> muncul di
 * db.devices -> LegacyDeviceMatcherService (cpe:auto-match-legacy-devices)
 * / CpeBindingService::reconcilePending() (cpe:reconcile) / bind manual
 * dari halaman pelanggan membuat baris cpe_devices dengan
 * genieacs_device_id = _id GenieACS. Selama itu belum terjadi, device
 * menggantung di sini.
 *
 * GenieACS tidak punya konsep tenant — daftar device mentahnya global.
 * Set "sudah ter-bind" karena itu dicek withoutGlobalScopes() (device
 * yang di-claim tenant mana pun = bukan unbound). Pengayaan boss_state
 * (apakah serial-nya sudah dikenal sebagai cpe_devices, mis.
 * pending_first_connect) tetap tenant-scoped supaya tidak membocorkan
 * nama pelanggan tenant lain.
 *
 * Query bulk ke genieacs-nbi (satu-satunya bagian yang berat) di-cache
 * pendek (config('services.genieacs.unbound_cache_ttl')) karena section
 * ini di-auto-reload; diff terhadap cpe_devices selalu fresh per request.
 */
class UnboundGenieacsDeviceService
{
    /**
     * Path MAC yang lazim terisi di fleet ini (best-effort — mayoritas
     * device TIDAK menaruh MAC di parameter tree kecuali ada preset yang
     * menariknya, lihat CLAUDE.md "GenieACS Connected Clients").
     */
    private const MAC_PATHS = [
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.MACAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress',
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
        'Device.Ethernet.Interface.1.MACAddress',
    ];

    private const ACS_URL_PATHS = [
        'InternetGatewayDevice.ManagementServer.URL._value',
        'Device.ManagementServer.URL._value',
    ];

    public function __construct(private readonly GenieAcsClientService $client) {}

    /**
     * @return array<int, array{
     *   genieacs_id: string, serial_number: ?string, manufacturer: ?string,
     *   product_class: ?string, oui: ?string, mac_address: ?string,
     *   registered_at: ?string, last_inform_at: ?string, acs_url: ?string,
     *   boss_state: string, boss_customer: ?string
     * }>
     */
    public function list(): array
    {
        $boundIds = CpeDevice::query()
            ->withoutGlobalScopes()
            ->whereNotNull('genieacs_device_id')
            ->pluck('genieacs_device_id')
            ->flip();

        $unbound = [];
        foreach ($this->allGenieAcsDevices() as $device) {
            $id = $device['_id'] ?? null;

            if ($id === null || $boundIds->has($id) || $this->isProbe($device)) {
                continue;
            }

            $unbound[] = $this->shape($device);
        }

        $this->enrichBossState($unbound);

        usort($unbound, fn ($a, $b) => strcmp((string) $b['last_inform_at'], (string) $a['last_inform_at']));

        return $unbound;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allGenieAcsDevices(): array
    {
        $projection = array_merge(
            ['_id', '_lastInform', '_registered', '_deviceId'],
            ['InternetGatewayDevice.ManagementServer.URL', 'Device.ManagementServer.URL'],
            self::MAC_PATHS,
        );

        $fetch = fn (): array => $this->client->queryDevices(['_id' => ['$ne' => null]], $projection);

        $ttl = (int) config('services.genieacs.unbound_cache_ttl', 15);

        return $ttl > 0
            ? Cache::remember('genieacs:all-device-identities', $ttl, $fetch)
            : $fetch();
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function isProbe(array $device): bool
    {
        $id = $device['_deviceId'] ?? [];

        return ($id['_Manufacturer'] ?? null) === 'probe' && ($id['_ProductClass'] ?? null) === 'probe';
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    private function shape(array $device): array
    {
        $deviceId = $device['_deviceId'] ?? [];

        return [
            'genieacs_id' => $device['_id'],
            'serial_number' => $deviceId['_SerialNumber'] ?? null,
            'manufacturer' => $deviceId['_Manufacturer'] ?? null,
            'product_class' => $deviceId['_ProductClass'] ?? null,
            'oui' => $deviceId['_OUI'] ?? null,
            'mac_address' => $this->firstValue($device, self::MAC_PATHS, '._value'),
            'registered_at' => $this->iso($device['_registered'] ?? null),
            'last_inform_at' => $this->iso($device['_lastInform'] ?? null),
            'acs_url' => $this->firstValue($device, self::ACS_URL_PATHS),
            'boss_state' => 'unknown',
            'boss_customer' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $unbound
     */
    private function enrichBossState(array &$unbound): void
    {
        $serials = array_values(array_filter(array_column($unbound, 'serial_number')));

        if ($serials === []) {
            return;
        }

        $known = CpeDevice::query()
            ->whereIn('serial_number', $serials)
            ->with('customer:id,name')
            ->get()
            ->keyBy('serial_number');

        foreach ($unbound as &$row) {
            $match = $row['serial_number'] !== null ? $known->get($row['serial_number']) : null;

            if ($match !== null) {
                $row['boss_state'] = 'serial_known';
                $row['boss_customer'] = $match->customer?->name;
            }
        }

        unset($row);
    }

    /**
     * @param  array<string, mixed>  $device
     * @param  array<int, string>  $paths
     */
    private function firstValue(array $device, array $paths, string $suffix = ''): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($device, $path.$suffix);

            if (is_string($value) && $value !== '' && $value !== '00:00:00:00:00:00') {
                return $value;
            }
        }

        return null;
    }

    private function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
