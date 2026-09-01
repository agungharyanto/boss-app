<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.16.0 Langkah 11 — thin wrapper around the self-hosted OSRM engine
 * (docker-compose.yml `osrm` service) for the "Cek Jalur ke ODP" sales
 * feature. Returns the real road route(s) a motorbike visit / cable run
 * would follow, with EVERY alternative OSRM finds.
 *
 * When OSRM is unreachable, slow, or returns no usable route, this
 * degrades to a single straight-line estimate flagged `is_fallback` and
 * logs a warning — never a silent failure, per the sprint brief.
 */
class RoutingService
{
    /**
     * @return list<array{label: string, distance_meters: float, duration_seconds: ?float, geometry: array{type: string, coordinates: list<array{0: float, 1: float}>}, is_fallback: bool}>
     */
    public function getRouteOptions(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $options = $this->queryOsrm($fromLat, $fromLng, $toLat, $toLng);

        if ($options === null || $options === []) {
            return [$this->straightLineFallback($fromLat, $fromLng, $toLat, $toLng)];
        }

        // shortest first — the first option is the "Rekomendasi"
        usort($options, fn (array $a, array $b) => $a['distance_meters'] <=> $b['distance_meters']);

        return $this->relabel($options);
    }

    /**
     * @return list<array<string, mixed>>|null null on any failure
     */
    private function queryOsrm(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $base = rtrim((string) config('services.osrm.url'), '/');
        $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
        $url = "{$base}/route/v1/driving/{$coords}";

        try {
            $response = Http::timeout((int) config('services.osrm.timeout', 5))
                ->acceptJson()
                ->get($url, [
                    'alternatives' => 'true',
                    'overview' => 'full',
                    'geometries' => 'geojson',
                ]);
        } catch (Throwable $e) {
            Log::warning('OSRM routing request failed, falling back to straight line', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            Log::warning('OSRM routing returned no usable result, falling back to straight line', [
                'status' => $response->status(),
                'code' => $response->json('code'),
            ]);

            return null;
        }

        $routes = $response->json('routes') ?? [];
        $options = [];

        foreach ($routes as $route) {
            $geometry = $route['geometry'] ?? null;

            if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
                continue;
            }

            $options[] = [
                'distance_meters' => (float) ($route['distance'] ?? 0),
                'duration_seconds' => isset($route['duration']) ? (float) $route['duration'] : null,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => array_map(
                        fn ($pair) => [(float) $pair[0], (float) $pair[1]],
                        $geometry['coordinates'] ?? [],
                    ),
                ],
                'is_fallback' => false,
            ];
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function relabel(array $options): array
    {
        $labelled = [];

        foreach (array_values($options) as $i => $option) {
            $option['label'] = $i === 0
                ? 'Rekomendasi'
                : 'Alternatif '.chr(ord('A') + $i); // index 1 -> "Alternatif B"
            $labelled[] = $option;
        }

        return $labelled;
    }

    /**
     * @return array{label: string, distance_meters: float, duration_seconds: ?float, geometry: array{type: string, coordinates: list<array{0: float, 1: float}>}, is_fallback: bool}
     */
    private function straightLineFallback(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        return [
            'label' => 'Estimasi lurus (routing tidak tersedia)',
            'distance_meters' => $this->haversineMeters($fromLat, $fromLng, $toLat, $toLng),
            'duration_seconds' => null,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]],
            ],
            'is_fallback' => true,
        ];
    }

    public function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
