<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\ModemType;
use App\Models\PppPackage;
use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use App\Services\Network\WanConfigTemplateSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.5 (koreksi arsitektur, kembali ke matrix — dikonfirmasi Agung)
 * — WanConfigTemplateSuggestionService sekarang resolve DUA sumber:
 * customers.ppp_package_id (via cpe_devices.customer) DAN
 * cpe_devices.manufacturer (via modem_types.manufacturer_match_patterns,
 * logic TIDAK BERUBAH). Keduanya wajib ter-resolve; exact match
 * (ppp_package_id, modem_type_id) diutamakan, fallback ke template
 * Default paket (modem_type_id NULL) kalau exact match tidak ada.
 */
class WanConfigTemplateSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WanConfigTemplateSuggestionService
    {
        return app(WanConfigTemplateSuggestionService::class);
    }

    private function deviceForPackage(Tenant $tenant, ?PppPackage $package, string $manufacturer): CpeDevice
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'ppp_package_id' => $package?->id,
        ]);

        return CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'manufacturer' => $manufacturer,
        ]);
    }

    public function test_exact_match_package_and_modem_type_returns_that_template(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $exactTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemType->id]);
        // Template default paket ini juga ada — HARUS diabaikan karena exact match tersedia.
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, 'ZICG');

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($exactTemplate->id, $result->id);
    }

    /**
     * Tidak ada exact match untuk kombinasi (paket, modem) ini, tapi ADA
     * template Default paket-nya — dipakai sebagai fallback.
     */
    public function test_falls_back_to_the_packages_default_template_when_no_exact_match(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $defaultTemplate = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, 'ZICG');

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($defaultTemplate->id, $result->id);
    }

    /**
     * Customer tidak punya ppp_package_id sama sekali -> null, walaupun
     * modem ter-resolve dengan benar.
     */
    public function test_customer_without_a_package_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);

        $device = $this->deviceForPackage($tenant, null, 'ZICG');

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Modem ter-resolve, paket ter-resolve, tapi TIDAK ADA template sama
     * sekali untuk paket itu (baik exact maupun default) -> null.
     */
    public function test_a_package_with_no_template_at_all_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);

        $device = $this->deviceForPackage($tenant, $package, 'ZICG');

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Modem ambigu (match ke >1 Tipe Modem) -> null total, walaupun
     * paket + template default-nya ada — logic modem TIDAK BERUBAH dari
     * sebelumnya, tetap syarat wajib.
     */
    public function test_ambiguous_modem_type_match_returns_null_even_with_a_valid_package(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG,CIOT']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, 'ZICG');

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Manufacturer tidak match Tipe Modem mana pun -> null, walaupun
     * paket + template default-nya ada.
     */
    public function test_no_manufacturer_match_returns_null_even_with_a_valid_package(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, 'UNKNOWNOUI');

        $this->assertNull($this->service()->suggestFor($device));
    }

    public function test_empty_manufacturer_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, '');

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Normalisasi kapital/spasi — logic TIDAK BERUBAH dari sebelumnya.
     */
    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => ' zicg , ciot ']);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => $modemType->id]);

        $device = $this->deviceForPackage($tenant, $package, '  zicg  ');

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($template->id, $result->id);
    }

    /**
     * ModemType nonaktif tidak boleh ikut jadi kandidat match, meski
     * pattern-nya cocok — logic TIDAK BERUBAH.
     */
    public function test_an_inactive_modem_type_is_never_a_match_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG', 'is_active' => false]);
        $package = PppPackage::factory()->create(['tenant_id' => $tenant->id]);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'ppp_package_id' => $package->id, 'modem_type_id' => null]);

        $device = $this->deviceForPackage($tenant, $package, 'ZICG');

        $this->assertNull($this->service()->suggestFor($device));
    }
}
