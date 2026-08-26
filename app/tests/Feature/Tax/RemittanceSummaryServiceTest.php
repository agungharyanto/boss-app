<?php

namespace Tests\Feature\Tax;

use App\Models\KomdigiRemittanceSummary;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tax\RemittanceSummaryService;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Services\Tax\TaxCalculationService;
use App\Services\Tax\TaxComponentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RemittanceSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaxComponentService $componentService;

    private ResellerTaxPolicyService $policyService;

    private TaxCalculationService $calcService;

    private RemittanceSummaryService $remittanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->componentService = app(TaxComponentService::class);
        $this->policyService = app(ResellerTaxPolicyService::class);
        $this->calcService = app(TaxCalculationService::class);
        $this->remittanceService = app(RemittanceSummaryService::class);
    }

    public function test_generate_for_period_separates_direct_retail_from_reseller_aggregates(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $reseller = Reseller::factory()->for($tenant)->create();
        $ppn = $this->componentService->create(['code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11, 'effective_from' => now()->startOfMonth()->toDateString()]);
        $this->policyService->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());
        $this->policyService->setPolicy($reseller, $ppn, 'reseller_borne', null, now()->startOfMonth());

        // 2 direct-retail transactions, 3 reseller transactions.
        for ($i = 0; $i < 2; $i++) {
            $bd = $this->calcService->calculateForAmount(null, 1000000, now());
            $this->calcService->writeLedgerEntry($bd, null, null, null, now(), 'seeded');
        }
        for ($i = 0; $i < 3; $i++) {
            $bd = $this->calcService->calculateForAmount($reseller, 500000, now());
            $this->calcService->writeLedgerEntry($bd, $reseller, null, null, now(), 'seeded');
        }

        $this->remittanceService->generateForPeriod(now()->startOfMonth(), now()->endOfMonth());

        $direct = KomdigiRemittanceSummary::whereNull('reseller_id')->where('tax_component_id', $ppn->id)->first();
        $resellerSummary = KomdigiRemittanceSummary::where('reseller_id', $reseller->id)->where('tax_component_id', $ppn->id)->first();

        $this->assertNotNull($direct);
        $this->assertNotNull($resellerSummary);
        $this->assertEquals(2, $direct->transaction_count);
        $this->assertEquals(3, $resellerSummary->transaction_count);
        $this->assertEquals(220000.0, (float) $direct->total_tax_amount); // 11% of 2*1,000,000
        $this->assertEquals(165000.0, (float) $resellerSummary->total_tax_amount); // 11% of 3*500,000
        $this->assertEquals(0.0, (float) $direct->total_reseller_borne);
        $this->assertEquals(165000.0, (float) $resellerSummary->total_reseller_borne); // reseller_borne burden
    }

    public function test_voided_ledger_entries_are_excluded_from_the_aggregate(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $ppn = $this->componentService->create(['code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11, 'effective_from' => now()->startOfMonth()->toDateString()]);
        $this->policyService->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());

        $bd = $this->calcService->calculateForAmount(null, 1000000, now());
        $rows = $this->calcService->writeLedgerEntry($bd, null, null, null, now(), 'seeded');
        $rows[0]->update(['status' => 'voided']);

        $bd2 = $this->calcService->calculateForAmount(null, 500000, now());
        $this->calcService->writeLedgerEntry($bd2, null, null, null, now(), 'seeded');

        $this->remittanceService->generateForPeriod(now()->startOfMonth(), now()->endOfMonth());

        $summary = KomdigiRemittanceSummary::whereNull('reseller_id')->first();
        $this->assertEquals(1, $summary->transaction_count);
        $this->assertEquals(55000.0, (float) $summary->total_tax_amount); // only the 500,000 transaction
    }

    public function test_regenerating_a_finalized_period_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('superadmin');
        $this->actingAs($admin);

        $ppn = $this->componentService->create(['code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11, 'effective_from' => now()->startOfMonth()->toDateString()]);
        $this->policyService->setPolicy(null, $ppn, 'customer_borne', null, now()->startOfMonth());
        $bd = $this->calcService->calculateForAmount(null, 1000000, now());
        $this->calcService->writeLedgerEntry($bd, null, null, null, now(), 'seeded');

        $this->remittanceService->generateForPeriod(now()->startOfMonth(), now()->endOfMonth());
        $summary = KomdigiRemittanceSummary::whereNull('reseller_id')->first();
        $this->remittanceService->finalize($summary);

        $this->assertEquals('finalized', $summary->fresh()->status->value);

        $this->expectException(RuntimeException::class);
        $this->remittanceService->generateForPeriod(now()->startOfMonth(), now()->endOfMonth());
    }
}
