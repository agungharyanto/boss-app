<?php

namespace App\Services\Network;

use App\Models\CpeParameterMap;

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

        $maps = CpeParameterMap::query()
            ->where('oui', $oui)
            ->where('product_class', $productClass)
            ->get();

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
}
