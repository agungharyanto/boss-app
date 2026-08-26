<?php

namespace Tests\Feature\Tax;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Services\Tax\TaxCalculationService;
use App\Services\Tax\TaxComponentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaxComponentService $componentService;

    private ResellerTaxPolicyService $policyService;

    private TaxCalculationService $calcService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->componentService = app(TaxComponentService::class);
        $this->policyService = app(ResellerTaxPolicyService::class);
        $this->calcService = app(TaxCalculationService::class);
    }

    private function actingAsAdmin(): User
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_percentage_tax_is_calculated_correctly(): void
    {
        $this->actingAsAdmin();

        $ppn = $this->componentService->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        $this->policyService->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());

        $breakdown = $this->calcService->calculateForAmount(null, 1000000);

        $this->assertEquals(110000.0, $breakdown->totalTax);
        $this->assertEquals(1110000.0, $breakdown->grandTotal);
    }

    public function test_fixed_tax_is_calculated_correctly_and_ignores_base_amount(): void
    {
        $this->actingAsAdmin();

        $bhpUso = $this->componentService->create([
            'code' => 'BHP_USO', 'name' => 'BHP USO', 'type' => 'fixed', 'rate' => 5000,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        $this->policyService->setPolicy(null, $bhpUso, 'customer_borne', null, now()->startOfMonth());

        $small = $this->calcService->calculateForAmount(null, 10000);
        $large = $this->calcService->calculateForAmount(null, 10000000);

        $this->assertEquals(5000.0, $small->totalTax);
        $this->assertEquals(5000.0, $large->totalTax);
    }

    public function test_split_burden_customer_and_reseller_amounts_sum_to_tax_amount(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::first();
        $reseller = Reseller::factory()->for($tenant)->create();

        $ppn = $this->componentService->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        $this->policyService->setPolicy($reseller, $ppn, 'split', 33, now()->startOfMonth());

        // An amount chosen so 33% doesn't divide evenly, to actually
        // exercise the rounding-remainder logic.
        $breakdown = $this->calcService->calculateForAmount($reseller, 777777);

        $component = $breakdown->components[0];
        $this->assertEquals(
            round($component['tax_amount'], 2),
            round($component['customer_amount'] + $component['reseller_amount'], 2)
        );
    }

    public function test_component_with_no_resolved_policy_is_skipped(): void
    {
        $this->actingAsAdmin();

        $this->componentService->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        // No policy set at all.

        $breakdown = $this->calcService->calculateForAmount(null, 1000000);

        $this->assertCount(0, $breakdown->components);
        $this->assertEquals(0.0, $breakdown->totalTax);
    }

    public function test_writing_ledger_entries_creates_one_row_per_component(): void
    {
        $this->actingAsAdmin();

        $ppn = $this->componentService->create(['code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11, 'effective_from' => now()->startOfMonth()->toDateString()]);
        $bhpUso = $this->componentService->create(['code' => 'BHP_USO', 'name' => 'BHP', 'type' => 'fixed', 'rate' => 5000, 'effective_from' => now()->startOfMonth()->toDateString()]);
        $this->policyService->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());
        $this->policyService->setPolicy(null, $bhpUso, 'reseller_borne', null, now()->startOfMonth());

        $breakdown = $this->calcService->calculateForAmount(null, 500000);
        $rows = $this->calcService->writeLedgerEntry($breakdown, null, 'App\\Models\\Invoice', 42, now());

        $this->assertCount(2, $rows);
        $this->assertDatabaseHas('reseller_tax_ledger', ['reference_type' => 'App\\Models\\Invoice', 'reference_id' => 42, 'base_amount' => 500000]);
    }
}
