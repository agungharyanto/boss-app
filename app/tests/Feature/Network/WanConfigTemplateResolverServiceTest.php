<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\ModemType;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use App\Services\Network\WanConfigTemplateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.5 — WanConfigTemplateResolverService: resolve Template ON-DEMAND
 * dari kombinasi `customer.ppp_package_id` + `cpe_devices.modem_type_id`
 * (sumber kebenaran, TIDAK pernah re-matching OUI sendiri — beda dari
 * WanConfigTemplateSuggestionService lama yang sudah dihapus/dipecah).
 * Unit-level saja, belum dipanggil dari UI mana pun sesi ini (v0.12.6).
 */
class WanConfigTemplateResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WanConfigTemplateResolverService
    {
        return app(WanConfigTemplateResolverService::class);
    }

    private function deviceForPackage(Tenant $tenant, ?PppPackage $package, ?ModemType $modemType): CpeDevice
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => $package?->id,
        ]);

        return CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'modem_type_id' => $modemType?->id,
        ]);
    }

    public function test_exact_match_package_and_modem_type_returns_that_template(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $exactTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemType->id]);
        // Template default paket ini juga ada — HARUS diabaikan karena exact match tersedia.
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, $modemType);

        $result = $this->service()->resolveTemplateForDevice($device);

        $this->assertNotNull($result);
        $this->assertSame($exactTemplate->id, $result->id);
    }

    /**
     * Device sudah punya modem_type_id, tapi tidak ada exact match untuk
     * kombinasi (paket, modem) ini — fallback ke template Default paket.
     */
    public function test_falls_back_to_the_packages_default_template_when_no_exact_match(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $defaultTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, $modemType);

        $result = $this->service()->resolveTemplateForDevice($device);

        $this->assertNotNull($result);
        $this->assertSame($defaultTemplate->id, $result->id);
    }

    /**
     * Device BELUM punya modem_type_id sama sekali (null) — tidak ada
     * exact match yang mungkin dicari, langsung ke fallback default
     * paket (kalau ada).
     */
    public function test_a_device_without_a_modem_type_falls_back_to_the_default_template(): void
    {
        $tenant = Tenant::factory()->create();
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $defaultTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, null);

        $result = $this->service()->resolveTemplateForDevice($device);

        $this->assertNotNull($result);
        $this->assertSame($defaultTemplate->id, $result->id);
    }

    public function test_customer_without_a_package_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);

        $device = $this->deviceForPackage($tenant, null, $modemType);

        $this->assertNull($this->service()->resolveTemplateForDevice($device));
    }

    /**
     * Paket ter-resolve tapi TIDAK ADA template sama sekali untuk paket
     * itu (baik exact maupun default) -> null.
     */
    public function test_a_package_with_no_template_at_all_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id]);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);

        $device = $this->deviceForPackage($tenant, $package, $modemType);

        $this->assertNull($this->service()->resolveTemplateForDevice($device));
    }
}
