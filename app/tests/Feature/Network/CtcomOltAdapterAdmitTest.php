<?php

namespace Tests\Feature\Network;

use App\Models\OltDevice;
use App\Services\Network\OltSidecarClient;
use App\Services\Network\Provisioning\Olt\CtcomOltAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.23.8 LANGKAH D (REVISI) — admisi PER-DEVICE: CtcomOltAdapter::admit()
 * membaca KOLOM olt_devices.requires_bind_onu, bukan vendor string. Nol sentuh
 * device (OltSidecarClient di-mock).
 */
class CtcomOltAdapterAdmitTest extends TestCase
{
    private function mockSidecar(): MockObject&OltSidecarClient
    {
        $sidecar = $this->createMock(OltSidecarClient::class);
        $this->app->instance(OltSidecarClient::class, $sidecar);

        return $sidecar;
    }

    private function olt(?bool $requires): OltDevice
    {
        $olt = new OltDevice;
        $olt->id = 2;
        $olt->requires_bind_onu = $requires;

        return $olt;
    }

    public function test_requires_true_runs_bind_onu_and_confirm(): void
    {
        $sidecar = $this->mockSidecar();
        $sidecar->expects($this->once())->method('bindOnu')
            ->with(
                $this->isInstanceOf(OltDevice::class),
                $this->callback(fn (array $p): bool => $p['pon'] === 2
                    && $p['mac'] === 'ec:9b:2d:f2:f6:39'
                    && $p['onu_id'] === 1
                    && $p['name'] === 'TestE04ID'
                    && $p['desc'] === 'TestE04ID - 261067914698'),
                42,
            )
            // data = hasil-baca device (read-back): assigned+confirmed onu-id.
            ->willReturn(['success' => true, 'data' => ['assigned_onu_id' => 1, 'confirmed_onu_id' => 1, 'pon' => 2]]);

        $adapter = new CtcomOltAdapter('hsgq_e04id');
        $result = $adapter->admit($this->olt(true), [
            'pon' => 2, 'mac' => 'ec:9b:2d:f2:f6:39', 'onu_id' => 1,
            'name' => 'TestE04ID', 'desc' => 'TestE04ID - 261067914698', 'requested_by' => 42,
        ]);

        $this->assertTrue($result['admitted']);
        $this->assertSame('bind_onu', $result['mode']);
        $this->assertSame(2, $result['pon']);
        $this->assertSame(1, $result['onu_id']); // dari confirmed_onu_id device, bukan caller
    }

    public function test_requires_true_without_caller_onu_id_uses_device_readback(): void
    {
        // onu_id TIDAK disuplai caller → sidecar baca-balik dari device.
        $sidecar = $this->mockSidecar();
        $sidecar->expects($this->once())->method('bindOnu')
            ->with(
                $this->isInstanceOf(OltDevice::class),
                $this->callback(fn (array $p): bool => $p['pon'] === 2
                    && $p['mac'] === 'ec:9b:2d:f2:f6:39'
                    && ! array_key_exists('onu_id', $p)), // tak ada onu_id caller
                null,
            )
            ->willReturn(['success' => true, 'data' => ['assigned_onu_id' => 3, 'confirmed_onu_id' => 3, 'pon' => 2]]);

        $result = (new CtcomOltAdapter('hsgq_e04id'))->admit($this->olt(true), [
            'pon' => 2, 'mac' => 'ec:9b:2d:f2:f6:39',
        ]);

        $this->assertTrue($result['admitted']);
        $this->assertSame(3, $result['onu_id']); // id hasil-baca device
    }

    public function test_requires_false_is_noop_success_without_sidecar(): void
    {
        $sidecar = $this->mockSidecar();
        $sidecar->expects($this->never())->method('bindOnu');

        $result = (new CtcomOltAdapter('hsgq_g02id'))->admit($this->olt(false));

        $this->assertTrue($result['admitted']);
        $this->assertSame('auto_authorize', $result['mode']);
        $this->assertSame('none', $result['action']);
    }

    public function test_requires_null_throws_explicit_config_error(): void
    {
        $sidecar = $this->mockSidecar();
        $sidecar->expects($this->never())->method('bindOnu');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum diset requires_bind_onu');

        (new CtcomOltAdapter('hsgq_e04id'))->admit($this->olt(null));
    }

    public function test_requires_true_missing_context_throws_before_sidecar(): void
    {
        $sidecar = $this->mockSidecar();
        $sidecar->expects($this->never())->method('bindOnu');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("context['mac']");

        // pon+onu_id ada, mac hilang → ditolak sebelum menyentuh sidecar.
        (new CtcomOltAdapter('hsgq_e04id'))->admit($this->olt(true), ['pon' => 2, 'onu_id' => 1]);
    }

    public function test_sidecar_failure_bubbles_as_runtime_exception(): void
    {
        $sidecar = $this->mockSidecar();
        $sidecar->method('bindOnu')->willReturn(['success' => false, 'error' => 'Device menolak']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Device menolak');

        (new CtcomOltAdapter('hsgq_e04id'))->admit($this->olt(true), [
            'pon' => 2, 'mac' => 'ec:9b:2d:f2:f6:39', 'onu_id' => 1,
        ]);
    }
}
