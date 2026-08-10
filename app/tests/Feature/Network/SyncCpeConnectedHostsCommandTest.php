<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCpeConnectedHostsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Only `online` devices are worth polling — pending/offline ones can't
     * have a meaningful GenieACS Hosts read anyway (App\Services\Network\
     * CpeConnectedHostsService itself also no-ops safely without a
     * genieacs_device_id, this just confirms the command's own filter).
     */
    public function test_only_syncs_online_devices(): void
    {
        Http::fake([
            '*genieacs-nbi*' => Http::response([[
                '_id' => 'AABBCC-ONT-SNONLINE',
                '_deviceId' => ['_OUI' => 'AABBCC', '_ProductClass' => 'ONT', '_SerialNumber' => 'SNONLINE'],
                'InternetGatewayDevice' => ['LANDevice' => ['1' => ['Hosts' => ['Host' => [
                    '1' => [
                        'Active' => ['_value' => true, '_type' => 'xsd:boolean'],
                        'HostName' => ['_value' => 'Phone', '_type' => 'xsd:string'],
                        'IPAddress' => ['_value' => '192.168.1.2', '_type' => 'xsd:string'],
                        'MACAddress' => ['_value' => 'AA:AA:AA:AA:AA:AA', '_type' => 'xsd:string'],
                    ],
                ]]]]],
            ]], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $online = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'online',
            'genieacs_device_id' => 'AABBCC-ONT-SNONLINE',
        ]);
        $pending = CpeDevice::factory()->pendingFirstConnect()->create(['tenant_id' => $tenant->id]);

        $this->artisan('cpe:sync-connected-hosts')->assertSuccessful();

        $this->assertDatabaseHas('cpe_connected_hosts', ['cpe_device_id' => $online->id, 'mac_address' => 'AA:AA:AA:AA:AA:AA']);
        $this->assertDatabaseMissing('cpe_connected_hosts', ['cpe_device_id' => $pending->id]);
    }
}
