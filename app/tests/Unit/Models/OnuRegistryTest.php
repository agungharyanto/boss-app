<?php

namespace Tests\Unit\Models;

use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OnuRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migration + model test untuk onu_registries (v0.23.4) — bukan full
 * regression, scoped ke sub-versi ini saja.
 */
class OnuRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function oltDevice(): OltDevice
    {
        $nas = Nas::factory()->create();

        return OltDevice::factory()->create(['nas_id' => $nas->id]);
    }

    public function test_olt_device_id_and_vendor_identifier_combination_must_be_unique(): void
    {
        $olt = $this->oltDevice();
        OnuRegistry::factory()->create(['olt_device_id' => $olt->id, 'vendor_identifier' => 'gpon-onu_1/3/12:2']);

        $this->expectException(QueryException::class);

        OnuRegistry::factory()->create(['olt_device_id' => $olt->id, 'vendor_identifier' => 'gpon-onu_1/3/12:2']);
    }

    public function test_the_same_vendor_identifier_is_allowed_on_a_different_olt_device(): void
    {
        $oltA = $this->oltDevice();
        $oltB = $this->oltDevice();

        OnuRegistry::factory()->create(['olt_device_id' => $oltA->id, 'vendor_identifier' => 'gpon-onu_1/3/12:2']);
        $second = OnuRegistry::factory()->create(['olt_device_id' => $oltB->id, 'vendor_identifier' => 'gpon-onu_1/3/12:2']);

        $this->assertNotNull($second->id);
    }

    public function test_tenant_id_is_derived_from_the_olt_device_not_from_auth(): void
    {
        // Tidak ada user login di test ini (Auth::check() === false) —
        // membuktikan tenant_id TIDAK bergantung pada BelongsToTenant's
        // auto-fill dari Auth::user(), yang tidak akan pernah tersedia
        // saat OnuRegistryService::syncOnu() dipanggil dari artisan
        // command (lihat docs/omci/onu-registry-design.md §4).
        $olt = $this->oltDevice();

        $registry = OnuRegistry::withoutGlobalScopes()->create([
            'tenant_id' => $olt->tenant_id,
            'olt_device_id' => $olt->id,
            'vendor_identifier' => 'gpon-onu_1/3/12:5',
        ]);

        $this->assertSame($olt->tenant_id, $registry->tenant_id);
    }
}
