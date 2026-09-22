<?php

namespace Tests\Feature\Network;

use App\Enums\OltAccessProtocol;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Services\Network\OltSidecarClient;
use App\Support\OltSidecarHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OltSidecarClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.olt_sidecar.url' => 'http://olt-sidecar-test:8080',
            'services.olt_sidecar.hmac_secret' => 'test-shared-secret',
        ]);
    }

    private function makeOltDevice(string $manufacturer, string $model, OltAccessProtocol $protocol): OltDevice
    {
        $olt = OltManufacturer::factory()->create(['name' => $manufacturer]);
        $modelRow = OltModel::factory()->create(['olt_manufacturer_id' => $olt->id, 'name' => $model]);
        // OltDeviceFactory's tenant_id closure reads $attributes['nas_id']
        // BEFORE that key is resolved unless a concrete nas_id is passed
        // in explicitly (known factory-attribute-order gotcha — see
        // CLAUDE.md "IP Pool Pelanggan (v0.14.2)" for the full story).
        $nas = Nas::factory()->create();

        return OltDevice::factory()->create([
            'nas_id' => $nas->id,
            'olt_model_id' => $modelRow->id,
            'access_protocol' => $protocol,
            'ip_address' => '10.168.100.5',
            'ssh_port' => 22,
            'ssh_username' => 'boss',
            'ssh_password' => 'super-rahasia',
            'telnet_port' => 23323,
            'telnet_username' => 'smartolt',
            'telnet_password' => 'telnet-rahasia',
        ]);
    }

    public function test_read_signs_the_exact_raw_body_and_posts_to_the_sidecar_url(): void
    {
        Http::fake([
            'olt-sidecar-test:8080/*' => Http::response([
                'success' => true,
                'operation' => 'show_version',
                'olt_device_id' => 1,
                'data' => ['lines' => ['Firmware: 1.0']],
                'raw_excerpt' => null,
                'device_message' => null,
            ], 200),
        ]);

        $device = $this->makeOltDevice('HSGQ', 'HSGQ-E04ID', OltAccessProtocol::Ssh);

        $result = app(OltSidecarClient::class)->read($device, 'show_version');

        $this->assertTrue($result['success']);
        $this->assertSame(['lines' => ['Firmware: 1.0']], $result['data']);

        Http::assertSent(function ($request) use ($device) {
            $this->assertSame("http://olt-sidecar-test:8080/olt/{$device->id}/read", $request->url());

            $timestamp = (int) $request->header('X-Olt-Timestamp')[0];
            $signature = $request->header('X-Olt-Signature')[0];
            $hmac = new OltSidecarHmac('test-shared-secret');

            $this->assertTrue($hmac->verify($request->body(), $signature, $timestamp));

            $payload = json_decode($request->body(), true);
            $this->assertSame('hsgq_e04id', $payload['vendor']);
            $this->assertSame('show_version', $payload['operation']);
            // Dibandingkan terhadap satu sumber kebenaran resmi
            // (OltDevice::sidecarConnectionPayload()) — bukan literal
            // field satu-satu, supaya test ini genuinely membuktikan
            // client memakai method terpusat, bukan kebetulan cocok.
            $this->assertSame($device->fresh()->sidecarConnectionPayload(), $payload['connection']);

            return true;
        });
    }

    public function test_read_resolves_g02id_and_zte_c300_vendor_keys_correctly(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => null, 'raw_excerpt' => null, 'device_message' => null], 200),
        ]);

        $g02id = $this->makeOltDevice('HSGQ', 'HSGQ-G02ID', OltAccessProtocol::Ssh);
        app(OltSidecarClient::class)->read($g02id, 'show_version');

        $zte = $this->makeOltDevice('ZTE', 'C300', OltAccessProtocol::Telnet);
        app(OltSidecarClient::class)->read($zte, 'onu_uncfg_list');

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['vendor'] === 'hsgq_g02id' || $payload['vendor'] === 'zte_c300';
        });

        $bodies = collect(Http::recorded())
            ->map(fn ($pair) => json_decode($pair[0]->body(), true)['vendor']);

        $this->assertTrue($bodies->contains('hsgq_g02id'));
        $this->assertTrue($bodies->contains('zte_c300'));
    }

    public function test_read_builds_a_telnet_connection_block_for_zte(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => null, 'raw_excerpt' => null, 'device_message' => null], 200),
        ]);

        $zte = $this->makeOltDevice('ZTE', 'C300', OltAccessProtocol::Telnet);
        app(OltSidecarClient::class)->read($zte, 'onu_uncfg_list');

        Http::assertSent(function ($request) use ($zte) {
            $payload = json_decode($request->body(), true);

            $this->assertSame($zte->fresh()->sidecarConnectionPayload(), $payload['connection']);

            return true;
        });
    }

    public function test_read_throws_a_clear_error_for_an_olt_device_the_sidecar_does_not_support(): void
    {
        Http::fake();

        $olt = OltManufacturer::factory()->create(['name' => 'Huawei']);
        $model = OltModel::factory()->create(['olt_manufacturer_id' => $olt->id, 'name' => 'MA5800']);
        $nas = Nas::factory()->create();
        $device = OltDevice::factory()->create(['nas_id' => $nas->id, 'olt_model_id' => $model->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum didukung sidecar');

        app(OltSidecarClient::class)->read($device, 'show_version');

        Http::assertNothingSent();
    }
}
