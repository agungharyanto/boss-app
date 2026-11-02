<?php

namespace Tests\Feature\Company;

use App\Models\CompanyProfile;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Services\Company\CompanyProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CompanyProfileService
    {
        return app(CompanyProfileService::class);
    }

    public function test_for_tenant_creates_a_singleton_row(): void
    {
        $tenant = Tenant::factory()->create();

        $a = $this->service()->forTenant($tenant->id);
        $b = $this->service()->forTenant($tenant->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CompanyProfile::where('tenant_id', $tenant->id)->count());
    }

    public function test_legal_name_falls_back_to_tenant_name_when_profile_and_env_empty(): void
    {
        config(['invoice.company_name' => '']);
        $tenant = Tenant::factory()->create(['name' => 'ISP Contoh']);

        $resolved = $this->service()->resolveFor($tenant->id);

        $this->assertSame('ISP Contoh', $resolved['legal_name']);
        // brand kosong → ikut legal_name.
        $this->assertSame('ISP Contoh', $resolved['brand_name']);
    }

    public function test_profile_values_win_over_fallbacks(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Contoh']);
        CompanyProfile::create([
            'tenant_id' => $tenant->id,
            'legal_name' => 'PT Jaringan Nusantara',
            'brand_name' => 'NusaNet',
        ]);

        $resolved = $this->service()->resolveFor($tenant->id);

        $this->assertSame('PT Jaringan Nusantara', $resolved['legal_name']);
        $this->assertSame('NusaNet', $resolved['brand_name']);
    }

    public function test_cs_resolves_reseller_override_then_tenant_then_placeholder(): void
    {
        config(['invoice.company_phone' => '']);
        $tenant = Tenant::factory()->create();

        // Belum ada apa pun → placeholder.
        $this->assertSame(
            CompanyProfileService::CS_UNSET_PLACEHOLDER,
            $this->service()->resolveFor($tenant->id)['cs'],
        );

        // CS tenant terisi → dipakai.
        CompanyProfile::create(['tenant_id' => $tenant->id, 'cs_whatsapp' => '0800-TENANT']);
        $this->assertSame('0800-TENANT', $this->service()->resolveFor($tenant->id)['cs']);

        // Reseller override menang atas CS tenant.
        $reseller = Reseller::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reseller A',
            'slug' => 'reseller-a',
            'cs_whatsapp' => '0899-RESELLER',
        ]);
        $this->assertSame('0899-RESELLER', $this->service()->resolveFor($tenant->id, $reseller->id)['cs']);

        // Reseller tanpa override → jatuh ke CS tenant lagi.
        $resellerNoCs = Reseller::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reseller B',
            'slug' => 'reseller-b',
        ]);
        $this->assertSame('0800-TENANT', $this->service()->resolveFor($tenant->id, $resellerNoCs->id)['cs']);
    }

    public function test_env_invoice_company_is_the_last_fallback_before_placeholder(): void
    {
        config(['invoice.company_phone' => '0800-ENV', 'invoice.company_name' => 'PT Env', 'invoice.company_address' => 'Alamat Env']);
        $tenant = Tenant::factory()->create();

        $resolved = $this->service()->resolveFor($tenant->id);

        $this->assertSame('0800-ENV', $resolved['cs']);
        $this->assertSame('PT Env', $resolved['legal_name']);
        $this->assertSame('Alamat Env', $resolved['address']);
    }

    public function test_operating_hours_falls_back_to_24_jam_when_empty_but_keeps_set_value(): void
    {
        $tenant = Tenant::factory()->create();

        // Kosong → "24 Jam".
        $this->assertSame('24 Jam', $this->service()->resolveFor($tenant->id)['operating_hours']);

        // Terisi → nilai itu dipertahankan.
        CompanyProfile::create(['tenant_id' => $tenant->id, 'operating_hours' => 'Senin–Jumat 09.00–17.00']);
        $this->assertSame('Senin–Jumat 09.00–17.00', $this->service()->resolveFor($tenant->id)['operating_hours']);
    }

    public function test_missing_lists_unset_critical_fields(): void
    {
        config(['invoice.company_name' => '', 'invoice.company_phone' => '', 'invoice.company_address' => '']);
        $tenant = Tenant::factory()->create();

        $missing = $this->service()->missing($tenant->id);

        $this->assertContains('Nama perusahaan (legal)', $missing);
        $this->assertContains('Nomor Customer Service', $missing);
        $this->assertContains('Alamat perusahaan', $missing);

        CompanyProfile::create([
            'tenant_id' => $tenant->id,
            'legal_name' => 'PT Ada',
            'cs_whatsapp' => '0812',
            'address' => 'Jl. Ada',
        ]);

        $this->assertSame([], $this->service()->missing($tenant->id));
    }
}
