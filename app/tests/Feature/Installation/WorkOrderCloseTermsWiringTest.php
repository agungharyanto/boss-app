<?php

namespace Tests\Feature\Installation;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\InstallationTerms;
use App\Models\PppPackage;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Company\InstallationTermsService;
use App\Services\Company\TermsRenderer;
use App\Services\Installation\WorkOrderCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.13.4.3.1 lanjutan — sambungan ke Profil Perusahaan + S&K dinamis.
 * Menguji WorkOrderCloseService::renderedTerms() (dipakai form + snapshot).
 */
class WorkOrderCloseTermsWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Env invoice dikosongkan agar uji fallback profil kosong murni.
        config(['invoice.company_name' => '', 'invoice.company_phone' => '', 'invoice.company_address' => '']);
    }

    private function service(): WorkOrderCloseService
    {
        return app(WorkOrderCloseService::class);
    }

    public function test_rendered_snapshot_fills_company_name_nominal_and_cs_without_raw_placeholders(): void
    {
        $wo = WorkOrder::factory()->create();
        $tenantId = $wo->tenant_id;

        CompanyProfile::create([
            'tenant_id' => $tenantId,
            'legal_name' => 'PT Uji Sejahtera',
            'address' => 'Jl. PT No. 1',
            'cs_whatsapp' => '0812-CS-TENANT',
        ]);

        $pkg = PppPackage::factory()->create(['name' => 'HomeFixed 10 Mbps', 'sell_price' => 150000]);

        Customer::withoutGlobalScopes()->whereKey($wo->customer_id)->update([
            'name' => 'Budi Santoso',
            'address' => 'Jl. Melati 10',
            'ppp_package_id' => $pkg->id,
            'reseller_id' => null,
        ]);

        $rendered = $this->service()->renderedTerms($wo->fresh());

        $this->assertSame('1', $rendered['version']);
        $this->assertStringContainsString('PT Uji Sejahtera', $rendered['text']);
        $this->assertStringContainsString('Rp 150.000 per bulan', $rendered['text']);
        $this->assertStringContainsString('0812-CS-TENANT', $rendered['text']);
        $this->assertStringContainsString('HomeFixed 10 Mbps', $rendered['text']);
        $this->assertStringContainsString('Budi Santoso', $rendered['text']);
        // Tidak boleh ada placeholder {..} mentah yang lolos ke snapshot.
        $this->assertDoesNotMatchRegularExpression('/\{[a-zA-Z0-9_]+\}/', $rendered['text']);
    }

    public function test_reseller_cs_override_is_used_when_customer_has_reseller(): void
    {
        $wo = WorkOrder::factory()->create();
        $tenantId = $wo->tenant_id;

        CompanyProfile::create(['tenant_id' => $tenantId, 'legal_name' => 'PT Uji', 'cs_whatsapp' => '0812-CS-TENANT']);
        $reseller = Reseller::create([
            'tenant_id' => $tenantId,
            'name' => 'Reseller A',
            'slug' => 'reseller-a-'.$tenantId,
            'cs_whatsapp' => '0899-RESELLER',
        ]);

        Customer::withoutGlobalScopes()->whereKey($wo->customer_id)->update(['reseller_id' => $reseller->id]);

        $text = $this->service()->renderedTerms($wo->fresh())['text'];

        $this->assertStringContainsString('0899-RESELLER', $text);
        $this->assertStringNotContainsString('0812-CS-TENANT', $text);
    }

    public function test_empty_profile_falls_back_and_does_not_block(): void
    {
        $wo = WorkOrder::factory()->create();

        // Tanpa CompanyProfile, tanpa env, tanpa paket → tetap ter-render
        // (tidak melempar), CS = placeholder, perusahaan = nama tenant.
        Customer::withoutGlobalScopes()->whereKey($wo->customer_id)->update(['ppp_package_id' => null]);

        $rendered = $this->service()->renderedTerms($wo->fresh());

        $this->assertSame('1', $rendered['version']);
        $this->assertStringContainsString('[kontak belum diatur]', $rendered['text']);
        $tenantName = Tenant::whereKey($wo->tenant_id)->value('name');
        $this->assertStringContainsString($tenantName, $rendered['text']);
        $this->assertDoesNotMatchRegularExpression('/\{[a-zA-Z0-9_]+\}/', $rendered['text']);
    }

    public function test_active_terms_body_has_no_unknown_variables(): void
    {
        $tenant = Tenant::factory()->create();
        $terms = app(InstallationTermsService::class)->activeOrSeedDefault($tenant->id);

        $this->assertSame([], app(TermsRenderer::class)->unknownVariables($terms->body));
    }

    public function test_active_or_seed_default_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $service = app(InstallationTermsService::class);

        $first = $service->activeOrSeedDefault($tenant->id);
        $second = $service->activeOrSeedDefault($tenant->id);

        $this->assertSame(1, $first->version);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InstallationTerms::where('tenant_id', $tenant->id)->count());
    }
}
