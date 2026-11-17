<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Services\Whatsapp\WhatsappGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v0.13.5.4 (TAHAP A) — caption pelanggan memakai nama & CS yang SAMA dengan
 * PDF (CompanyProfileService::resolveFor), bukan nama tenant mentah.
 */
class CaptionCompanyIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function vars(Customer $customer, ?Invoice $invoice = null): array
    {
        $svc = app(WhatsappGatewayService::class);
        $m = new ReflectionMethod($svc, 'buildVariables');
        $m->setAccessible(true);

        return $m->invoke($svc, WhatsappEventType::PaymentReceived, $customer, $invoice);
    }

    public function test_caption_uses_company_profile_brand_and_cs(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        CompanyProfile::create([
            'tenant_id' => $tenant->id,
            'legal_name' => 'PT Bajastu Teknologi Waringin',
            'brand_name' => 'TOKEN WIFI',
            'cs_whatsapp' => '0899111222',
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $vars = $this->vars($customer);
        $this->assertSame('TOKEN WIFI', $vars['company_name']);
        $this->assertSame('0899111222', $vars['cs_number']);
    }

    public function test_brand_falls_back_to_legal_then_tenant_name(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        // Profil tanpa brand → pakai legal.
        CompanyProfile::create(['tenant_id' => $tenant->id, 'legal_name' => 'PT Legal Saja']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $this->assertSame('PT Legal Saja', $this->vars($customer)['company_name']);

        // Tenant tanpa profil sama sekali → fallback nama tenant.
        $tenant2 = Tenant::factory()->create(['name' => 'ISP Kedua']);
        $customer2 = Customer::factory()->create(['tenant_id' => $tenant2->id, 'reseller_id' => null]);
        $this->assertSame('ISP Kedua', $this->vars($customer2)['company_name']);
    }

    public function test_reseller_cs_override_applies_to_caption(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        CompanyProfile::create(['tenant_id' => $tenant->id, 'brand_name' => 'TOKEN WIFI', 'cs_whatsapp' => '0899']);
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'cs_whatsapp' => '0812reseller']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);

        $vars = $this->vars($customer);
        // Brand tetap milik tenant; CS di-override reseller.
        $this->assertSame('TOKEN WIFI', $vars['company_name']);
        $this->assertSame('0812reseller', $vars['cs_number']);
    }
}
