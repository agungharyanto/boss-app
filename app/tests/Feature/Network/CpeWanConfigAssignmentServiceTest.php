<?php

namespace Tests\Feature\Network;

use App\Enums\WanConfigTemplateSource;
use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use App\Services\Network\CpeWanConfigAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * v0.12.5 — CpeWanConfigAssignmentService: autoAssignIfUnset() (self-
 * healing, tidak pernah menimpa assignment yang sudah ada) dan
 * assignManually() (override eksplisit, selalu menang).
 */
class CpeWanConfigAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CpeWanConfigAssignmentService
    {
        return app(CpeWanConfigAssignmentService::class);
    }

    public function test_auto_assign_if_unset_persists_a_match_as_auto_source(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertSame($template->id, $result->wan_config_template_id);
        $this->assertSame(WanConfigTemplateSource::Auto, $result->wan_config_template_source);
    }

    public function test_auto_assign_if_unset_never_overwrites_an_existing_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true]);
        $manualTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'manufacturer' => 'ZICG',
            'wan_config_template_id' => $manualTemplate->id,
            'wan_config_template_source' => WanConfigTemplateSource::Manual,
        ]);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertSame($manualTemplate->id, $result->wan_config_template_id);
        $this->assertSame(WanConfigTemplateSource::Manual, $result->wan_config_template_source);
    }

    public function test_auto_assign_if_unset_leaves_the_device_untouched_when_suggestion_is_ambiguous(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'UNKNOWNOUI']);

        $result = $this->service()->autoAssignIfUnset($device);

        $this->assertNull($result->wan_config_template_id);
        $this->assertNull($result->wan_config_template_source);
    }

    public function test_assign_manually_sets_manual_source_and_overrides_any_existing_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $autoTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true]);
        $manualTarget = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'wan_config_template_id' => $autoTemplate->id,
            'wan_config_template_source' => WanConfigTemplateSource::Auto,
        ]);

        $result = $this->service()->assignManually($device, $manualTarget->id);

        $this->assertSame($manualTarget->id, $result->wan_config_template_id);
        $this->assertSame(WanConfigTemplateSource::Manual, $result->wan_config_template_source);
    }

    public function test_assign_manually_with_null_clears_the_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $device = CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'wan_config_template_id' => $template->id,
            'wan_config_template_source' => WanConfigTemplateSource::Manual,
        ]);

        $result = $this->service()->assignManually($device, null);

        $this->assertNull($result->wan_config_template_id);
        $this->assertNull($result->wan_config_template_source);
    }

    public function test_assign_manually_with_a_nonexistent_template_id_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->assignManually($device, 999999);
    }

    /**
     * Template milik tenant LAIN tidak boleh bisa di-assign — guard
     * tenant-scoping eksplisit di assignManually(), bukan cuma andalkan
     * TenantScope dari Auth user.
     */
    public function test_assign_manually_with_a_template_from_a_different_tenant_throws(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $foreignTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenantB->id]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenantA->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->assignManually($device, $foreignTemplate->id);
    }
}
