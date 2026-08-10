<?php

namespace Tests\Feature\Network;

use App\Models\CpeConnectedHost;
use App\Models\CpeDevice;
use App\Models\Tenant;
use App\Services\Network\CpeConnectedHostsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpeConnectedHostsServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shape confirmed for real (v0.7.6 discovery) against a live ZTE
     * F663NV3.1 — host instance numbers under Hosts.Host are genuinely
     * non-sequential on real hardware (7/10/11/67/68 observed), never
     * assumed 1..N.
     *
     * @param  array<int, array{mac: string, hostname: ?string, ip: string, active: bool}>  $hostList
     */
    private function fakeDeviceWithHosts(string $genieAcsId, array $hostList): array
    {
        $hostEntries = [];
        $instance = 7;

        foreach ($hostList as $host) {
            $hostEntries[(string) $instance] = [
                'Active' => ['_value' => $host['active'], '_type' => 'xsd:boolean'],
                'HostName' => ['_value' => $host['hostname'] ?? '', '_type' => 'xsd:string'],
                'IPAddress' => ['_value' => $host['ip'], '_type' => 'xsd:string'],
                'MACAddress' => ['_value' => $host['mac'], '_type' => 'xsd:string'],
            ];
            $instance += 3;
        }

        return [
            '_id' => $genieAcsId,
            '_deviceId' => [
                '_Manufacturer' => 'ZICG', '_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B',
            ],
            'InternetGatewayDevice' => [
                'LANDevice' => [
                    '1' => [
                        'Hosts' => [
                            'Host' => $hostEntries,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function device(): CpeDevice
    {
        $tenant = Tenant::factory()->create();

        return CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
        ]);
    }

    public function test_new_mac_is_inserted_with_first_seen_at(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeDeviceWithHosts('F86CE1-F663NV3a-ZICG296C2E7B', [
                ['mac' => '12:E8:0C:44:B2:FF', 'hostname' => 'Infinix-SMART-6', 'ip' => '192.168.1.2', 'active' => true],
            ])], 200),
        ]);

        $device = $this->device();
        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $this->assertDatabaseHas('cpe_connected_hosts', [
            'cpe_device_id' => $device->id,
            'mac_address' => '12:E8:0C:44:B2:FF',
            'hostname' => 'Infinix-SMART-6',
            'ip_address' => '192.168.1.2',
            'is_active' => true,
        ]);
        $host = CpeConnectedHost::where('mac_address', '12:E8:0C:44:B2:FF')->firstOrFail();
        $this->assertNotNull($host->first_seen_at);
        $this->assertEqualsWithDelta(now()->timestamp, $host->first_seen_at->timestamp, 5);
    }

    public function test_existing_mac_updates_last_seen_at_but_never_first_seen_at(): void
    {
        $device = $this->device();
        $originalFirstSeen = now()->subDays(10);

        CpeConnectedHost::factory()->create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'mac_address' => '12:E8:0C:44:B2:FF',
            'is_active' => false,
            'first_seen_at' => $originalFirstSeen,
            'last_seen_at' => now()->subDays(5),
        ]);

        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeDeviceWithHosts('F86CE1-F663NV3a-ZICG296C2E7B', [
                ['mac' => '12:E8:0C:44:B2:FF', 'hostname' => 'Infinix-SMART-6', 'ip' => '192.168.1.2', 'active' => true],
            ])], 200),
        ]);

        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $host = CpeConnectedHost::where('mac_address', '12:E8:0C:44:B2:FF')->firstOrFail();
        $this->assertTrue($host->is_active);
        $this->assertEqualsWithDelta(now()->timestamp, $host->last_seen_at->timestamp, 5);
        // first_seen_at is untouched — same second as originally recorded.
        $this->assertEquals($originalFirstSeen->timestamp, $host->first_seen_at->timestamp);
    }

    public function test_mac_missing_from_this_poll_is_marked_inactive_not_deleted(): void
    {
        $device = $this->device();

        CpeConnectedHost::factory()->create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'is_active' => true,
        ]);

        // This poll reports a DIFFERENT MAC only — the old one is absent.
        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeDeviceWithHosts('F86CE1-F663NV3a-ZICG296C2E7B', [
                ['mac' => '11:22:33:44:55:66', 'hostname' => 'NewPhone', 'ip' => '192.168.1.9', 'active' => true],
            ])], 200),
        ]);

        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $this->assertDatabaseHas('cpe_connected_hosts', [
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'is_active' => false,
        ]);
        // Row still exists — never deleted.
        $this->assertDatabaseCount('cpe_connected_hosts', 2);
    }

    public function test_hostname_is_not_cleared_when_a_later_poll_reports_it_empty(): void
    {
        $device = $this->device();

        CpeConnectedHost::factory()->create([
            'cpe_device_id' => $device->id,
            'tenant_id' => $device->tenant_id,
            'mac_address' => '12:E8:0C:44:B2:FF',
            'hostname' => 'KnownPhoneName',
        ]);

        Http::fake([
            '*genieacs-nbi*' => Http::response([$this->fakeDeviceWithHosts('F86CE1-F663NV3a-ZICG296C2E7B', [
                ['mac' => '12:E8:0C:44:B2:FF', 'hostname' => null, 'ip' => '192.168.1.2', 'active' => true],
            ])], 200),
        ]);

        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $this->assertSame('KnownPhoneName', CpeConnectedHost::where('mac_address', '12:E8:0C:44:B2:FF')->firstOrFail()->hostname);
    }

    public function test_device_with_no_genieacs_id_does_nothing(): void
    {
        $device = $this->device();
        $device->update(['genieacs_device_id' => null]);

        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $this->assertDatabaseCount('cpe_connected_hosts', 0);
    }

    public function test_host_entry_without_a_mac_address_is_skipped(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'F86CE1-F663NV3a-ZICG296C2E7B',
                '_deviceId' => ['_OUI' => 'F86CE1', '_ProductClass' => 'F663NV3a', '_SerialNumber' => 'ZICG296C2E7B'],
                'InternetGatewayDevice' => [
                    'LANDevice' => [
                        '1' => [
                            'Hosts' => [
                                'Host' => [
                                    '7' => [
                                        'Active' => ['_value' => true, '_type' => 'xsd:boolean'],
                                        'HostName' => ['_value' => 'NoMacHost', '_type' => 'xsd:string'],
                                        // No MACAddress key at all.
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]], 200),
        ]);

        $device = $this->device();
        app(CpeConnectedHostsService::class)->syncFromGenieAcs($device);

        $this->assertDatabaseCount('cpe_connected_hosts', 0);
    }
}
