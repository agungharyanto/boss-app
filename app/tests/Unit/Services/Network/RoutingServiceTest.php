<?php

namespace Tests\Unit\Services\Network;

use App\Services\Network\RoutingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoutingServiceTest extends TestCase
{
    private function osrmResponse(array $routes): array
    {
        return ['code' => 'Ok', 'routes' => $routes];
    }

    private function route(float $distance, array $coords): array
    {
        return [
            'distance' => $distance,
            'duration' => $distance / 8,
            'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
        ];
    }

    public function test_parses_multiple_alternatives_sorted_shortest_first_with_labels(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse([
            $this->route(2500, [[106.80, -6.20], [106.81, -6.21], [106.82, -6.22]]),
            $this->route(1800, [[106.80, -6.20], [106.815, -6.205], [106.82, -6.22]]),
            $this->route(3100, [[106.80, -6.20], [106.79, -6.19], [106.82, -6.22]]),
        ]))]);

        $options = (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.22, 106.82);

        $this->assertCount(3, $options);
        $this->assertSame([1800.0, 2500.0, 3100.0], array_column($options, 'distance_meters'));
        $this->assertSame('Rekomendasi', $options[0]['label']);
        $this->assertSame('Alternatif B', $options[1]['label']);
        $this->assertSame('Alternatif C', $options[2]['label']);
        $this->assertFalse($options[0]['is_fallback']);
        $this->assertSame('LineString', $options[0]['geometry']['type']);
    }

    public function test_single_route_is_just_the_recommendation(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse([
            $this->route(900, [[106.80, -6.20], [106.805, -6.205]]),
        ]))]);

        $options = (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.205, 106.805);

        $this->assertCount(1, $options);
        $this->assertSame('Rekomendasi', $options[0]['label']);
    }

    public function test_falls_back_to_a_straight_line_when_osrm_is_unreachable(): void
    {
        Http::fake(fn (Request $r) => throw new ConnectionException('down'));

        $options = (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.22, 106.82);

        $this->assertCount(1, $options);
        $this->assertTrue($options[0]['is_fallback']);
        $this->assertStringContainsString('routing tidak tersedia', $options[0]['label']);
        $this->assertSame(
            [[106.80, -6.20], [106.82, -6.22]],
            $options[0]['geometry']['coordinates'],
        );
        // ~a few hundred metres to a few km — a real haversine number
        $this->assertGreaterThan(100, $options[0]['distance_meters']);
    }

    public function test_falls_back_on_a_non_2xx_response(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $options = (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.22, 106.82);

        $this->assertTrue($options[0]['is_fallback']);
    }

    public function test_falls_back_when_osrm_reports_a_non_ok_code(): void
    {
        Http::fake(['*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);

        $options = (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.22, 106.82);

        $this->assertCount(1, $options);
        $this->assertTrue($options[0]['is_fallback']);
    }

    public function test_hits_the_configured_osrm_url_with_alternatives_and_geojson(): void
    {
        Http::fake(['*' => Http::response($this->osrmResponse([$this->route(500, [[106.8, -6.2], [106.81, -6.21]])]))]);

        (new RoutingService)->getRouteOptions(-6.20, 106.80, -6.21, 106.81);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/route/v1/driving/106.8,-6.2;106.81,-6.21')
                && str_contains($request->url(), 'alternatives=true')
                && str_contains($request->url(), 'geometries=geojson')
                && str_contains($request->url(), 'overview=full');
        });
    }
}
