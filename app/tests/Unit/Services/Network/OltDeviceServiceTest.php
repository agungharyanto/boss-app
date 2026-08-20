<?php

namespace Tests\Unit\Services\Network;

use App\Enums\OltConnectionTestResult;
use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\OltDeviceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OltDeviceServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function ipProvider(): array
    {
        return [
            'RFC1918 10.x' => ['10.1.12.87', true],
            'RFC1918 172.16-31.x' => ['172.28.0.10', true],
            'RFC1918 192.168.x' => ['192.168.1.1', true],
            'public IP' => ['8.8.8.8', false],
            'another public IP' => ['144.79.52.0', false],
            'not an IP at all' => ['not-an-ip', false],
            // 172.15.x/172.32.x are OUTSIDE the RFC1918 172.16-31.x block —
            // the exact off-by-one boundary a hand-rolled string-prefix
            // check could get wrong; filter_var's own FILTER_FLAG_NO_PRIV_RANGE
            // handles this correctly without needing to hand-verify the math.
            '172.15.x is public, not RFC1918' => ['172.15.0.1', false],
            '172.32.x is public, not RFC1918' => ['172.32.0.1', false],
        ];
    }

    #[DataProvider('ipProvider')]
    public function test_is_private_ip(string $ip, bool $expected): void
    {
        $this->assertSame($expected, OltDeviceService::isPrivateIp($ip));
    }

    public function test_test_connection_reports_success_when_gateway_reports_reachable(): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class implements RouterOsGateway
        {
            public function ping(Nas $nas): array
            {
                return ['online' => true, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return true;
            }

            public function provisionApiUser(Nas $nas, string $a, string $b, string $c, string $d): array
            {
                return ['success' => true, 'message' => null];
            }
        });

        $nas = new Nas(['name' => 'NAS Test']);
        $result = app(OltDeviceService::class)->testConnection($nas, '10.1.1.5');

        $this->assertSame(OltConnectionTestResult::Success, $result['result']);
        $this->assertStringContainsString('10.1.1.5', $result['message']);
    }

    public function test_test_connection_reports_failed_when_gateway_reports_unreachable(): void
    {
        $this->app->bind(RouterOsGateway::class, fn () => new class implements RouterOsGateway
        {
            public function ping(Nas $nas): array
            {
                return ['online' => false, 'message' => null];
            }

            public function pingHost(Nas $nas, string $targetIp, int $count = 2): bool
            {
                return false;
            }

            public function provisionApiUser(Nas $nas, string $a, string $b, string $c, string $d): array
            {
                return ['success' => true, 'message' => null];
            }
        });

        $nas = new Nas(['name' => 'NAS Test']);
        $result = app(OltDeviceService::class)->testConnection($nas, '10.1.1.9');

        $this->assertSame(OltConnectionTestResult::Failed, $result['result']);
    }
}
