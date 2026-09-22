<?php

namespace Tests\Unit\Models;

use App\Enums\OltAccessProtocol;
use App\Models\Nas;
use App\Models\OltDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OltDevice::sidecarConnectionPayload() — satu-satunya titik resmi untuk
 * mengambil kredensial CLI admin device ini (v0.23.3). Lihat method itu
 * sendiri untuk alasan lengkap (insiden nyata 2026-09-22, docs/omci/
 * backlog-security.md poin 5) — App\Services\Network\OltSidecarClient
 * DAN test-nya sama-sama memanggil method ini, tidak ada kode lain yang
 * boleh membaca ssh_password/telnet_password langsung.
 */
class OltDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidecar_connection_payload_for_ssh_protocol(): void
    {
        $nas = Nas::factory()->create();
        $device = OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'access_protocol' => OltAccessProtocol::Ssh,
            'ip_address' => '10.168.100.5',
            'ssh_port' => 22,
            'ssh_username' => 'boss',
            'ssh_password' => 'dummy-ssh-password',
        ]);

        $payload = $device->sidecarConnectionPayload();

        $this->assertSame([
            'protocol' => 'ssh',
            'host' => '10.168.100.5',
            'port' => 22,
            'username' => 'boss',
            'password' => 'dummy-ssh-password',
        ], $payload);
    }

    public function test_sidecar_connection_payload_for_telnet_protocol(): void
    {
        $nas = Nas::factory()->create();
        $device = OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'access_protocol' => OltAccessProtocol::Telnet,
            'ip_address' => '10.168.100.34',
            'telnet_port' => 23323,
            'telnet_username' => 'smartolt',
            'telnet_password' => 'dummy-telnet-password',
        ]);

        $payload = $device->sidecarConnectionPayload();

        $this->assertSame([
            'protocol' => 'telnet',
            'host' => '10.168.100.34',
            'port' => 23323,
            'username' => 'smartolt',
            'password' => 'dummy-telnet-password',
        ], $payload);
    }

    public function test_sidecar_connection_payload_falls_back_to_default_port_when_not_set(): void
    {
        $nas = Nas::factory()->create();

        $sshDevice = OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'access_protocol' => OltAccessProtocol::Ssh,
            'ssh_port' => null,
        ]);
        $this->assertSame(22, $sshDevice->sidecarConnectionPayload()['port']);

        $telnetDevice = OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'access_protocol' => OltAccessProtocol::Telnet,
            'telnet_port' => null,
        ]);
        $this->assertSame(23, $telnetDevice->sidecarConnectionPayload()['port']);
    }
}
