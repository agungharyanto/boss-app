<?php

namespace Tests\Feature\Network;

use App\Enums\ModemTypeAssignmentSource;
use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Services\Network\CpeModemTypeAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v0.12.5 (rename dari CpeWanConfigAssignmentService — sekarang assign
 * Tipe Modem, bukan Template) — CpeModemTypeAssignmentService:
 * autoAssignIfUnset() (self-healing, OUI matching, tidak pernah menimpa
 * assignment yang sudah ada) dan assignManually() (override eksplisit,
 * selalu menang, tenant-scoped).
 */
class CpeModemTypeAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CpeModemTypeAssignmentService
    {
        return app(CpeModemTypeAssignmentService::class);
    }

    public function test_auto_assign_if_unset_persists_a_match_as_auto_source(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertSame($modemType->id, $result->modem_type_id);
        $this->assertSame(ModemTypeAssignmentSource::Auto, $result->modem_type_source);
    }

    public function test_auto_assign_if_unset_never_overwrites_an_existing_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $manualModemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'manufacturer' => 'ZICG',
            'modem_type_id' => $manualModemType->id,
            'modem_type_source' => ModemTypeAssignmentSource::Manual,
        ]);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertSame($manualModemType->id, $result->modem_type_id);
        $this->assertSame(ModemTypeAssignmentSource::Manual, $result->modem_type_source);
    }

    public function test_auto_assign_if_unset_leaves_the_device_untouched_when_suggestion_is_ambiguous(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'UNKNOWNOUI']);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertNull($result->modem_type_id);
        $this->assertNull($result->modem_type_source);
    }

    public function test_assign_manually_sets_manual_source_and_overrides_any_existing_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $autoModemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $manualTarget = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'modem_type_id' => $autoModemType->id,
            'modem_type_source' => ModemTypeAssignmentSource::Auto,
        ]);

        $result = $this->service()->assignManually($device, $manualTarget->id);

        $this->assertSame($manualTarget->id, $result->modem_type_id);
        $this->assertSame(ModemTypeAssignmentSource::Manual, $result->modem_type_source);
    }

    public function test_assign_manually_with_null_clears_the_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'modem_type_id' => $modemType->id,
            'modem_type_source' => ModemTypeAssignmentSource::Manual,
        ]);

        $result = $this->service()->assignManually($device, null);

        $this->assertNull($result->modem_type_id);
        $this->assertNull($result->modem_type_source);
    }

    public function test_assign_manually_with_a_nonexistent_modem_type_id_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->assignManually($device, 999999);
    }

    /**
     * ModemType milik tenant LAIN tidak boleh bisa di-assign — guard
     * tenant-scoping eksplisit di assignManually(), bukan cuma andalkan
     * TenantScope dari Auth user.
     */
    public function test_assign_manually_with_a_modem_type_from_a_different_tenant_throws(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $foreignModemType = ModemType::factory()->create(['tenant_id' => $tenantB->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenantA->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->assignManually($device, $foreignModemType->id);
    }
}
