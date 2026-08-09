<?php

namespace App\Services\Network;

use App\Enums\Tr069Root;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around genieacs-nbi's REST API — reached container-to-
 * container over boss-network only (config('services.genieacs.nbi_url'),
 * default http://genieacs-nbi:7557). genieacs-nbi has NO authentication of
 * its own; network isolation (no host port at all — see docker-compose.yml)
 * is the actual security boundary, not anything this class does.
 *
 * Response shape confirmed for real (not assumed from docs, which don't
 * show a full example) by sending a raw TR-069 Inform SOAP request to a
 * live genieacs-cwmp and reading back what genieacs-nbi actually returned:
 * each device is `{"_id": "OUI-ProductClass-SerialNumber", "_deviceId": {
 * "_Manufacturer", "_OUI", "_ProductClass", "_SerialNumber"}, "_lastInform":
 * ..., "InternetGatewayDevice": {"DeviceInfo": {"Manufacturer": {"_value":
 * ...}, ...}}}` — every leaf TR-069 parameter is an object with a `_value`
 * key, matching the nested path exactly (never a flat dotted key), and
 * `_deviceId` mirrors the Inform's own DeviceId struct regardless of which
 * root (TR-098/TR-181) the device otherwise uses.
 */
class GenieAcsClientService
{
    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.genieacs.nbi_url');
    }

    public function findDeviceById(string $id): ?array
    {
        $devices = $this->queryDevices(['_id' => $id]);

        return $devices[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $mongoQuery
     * @param  array<int, string>  $projection
     * @return array<int, array<string, mixed>>
     */
    public function queryDevices(array $mongoQuery, array $projection = []): array
    {
        $query = ['query' => json_encode($mongoQuery)];

        if ($projection !== []) {
            $query['projection'] = implode(',', $projection);
        }

        $response = Http::baseUrl($this->baseUrl)->get('/devices', $query)->throw();

        return $response->json() ?? [];
    }

    /**
     * Manufacturer/model/serial straight from `_deviceId` (present on every
     * device regardless of root — it comes from the Inform RPC's own
     * DeviceId struct, a required field of TR-069 itself, not a
     * vendor-specific parameter path) for manufacturer/serial, but
     * ModelName specifically has no DeviceId equivalent — that one genuinely
     * needs the DeviceInfo.* parameter tree, so root detection (TR-098 vs
     * TR-181) still matters for it. Tries InternetGatewayDevice.DeviceInfo.*
     * first, falls back to Device.DeviceInfo.* when the first is absent —
     * caller (CpeBindingService) persists which root actually resolved to
     * `cpe_devices.tr069_root` so later parameter queries don't have to
     * re-guess.
     *
     * @return array{manufacturer: ?string, model_name: ?string, serial_number: ?string, tr069_root: ?Tr069Root}
     */
    public function getStandardIdentity(string $deviceId): array
    {
        $device = $this->findDeviceById($deviceId);

        if ($device === null) {
            return ['manufacturer' => null, 'model_name' => null, 'serial_number' => null, 'tr069_root' => null];
        }

        $deviceIdStruct = $device['_deviceId'] ?? [];
        $manufacturer = $deviceIdStruct['_Manufacturer'] ?? null;
        $serialNumber = $deviceIdStruct['_SerialNumber'] ?? null;

        foreach ([Tr069Root::InternetGatewayDevice, Tr069Root::Device] as $root) {
            $modelName = $device[$root->value]['DeviceInfo']['ModelName']['_value'] ?? null;

            if ($modelName !== null) {
                return [
                    'manufacturer' => $manufacturer,
                    'model_name' => $modelName,
                    'serial_number' => $serialNumber,
                    'tr069_root' => $root,
                ];
            }
        }

        return [
            'manufacturer' => $manufacturer,
            'model_name' => null,
            'serial_number' => $serialNumber,
            'tr069_root' => null,
        ];
    }
}
