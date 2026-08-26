<?php

namespace Database\Seeders;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tax\ResellerTaxPolicyService;
use App\Services\Tax\TaxCalculationService;
use App\Services\Tax\TaxComponentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Dummy tax engine data for VISUAL/manual verification of v0.3.3 — local
 * environment only (guarded below, same posture as ResellerDemoSeeder).
 * Every reseller_tax_ledger row this creates has source='seeded', clearly
 * distinguishing it from whatever real invoice-triggered rows v0.3.4 will
 * eventually write (source='system').
 *
 * NOT idempotent by design — re-running adds more dummy components/
 * transactions rather than upserting. This seeder generates *transactional
 * test data*, not one-time reference setup, so re-running to get a bigger
 * dummy dataset is expected usage, not a bug.
 */
class TaxEngineDummyDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('TaxEngineDummyDataSeeder only runs in the local environment — aborting.');

            return;
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'isp-demo'],
            ['name' => 'ISP Demo', 'is_active' => true]
        );

        $admin = User::where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'superadmin'))
            ->first();

        if ($admin === null) {
            $this->command?->error('No superadmin user found for tenant "isp-demo" — run DemoUsersSeeder first.');

            return;
        }

        // TaxComponentService/ResellerTaxPolicyService/TaxCalculationService
        // all resolve "current tenant" via Auth (TenantScope), same
        // convention as every tenant-scoped query in this codebase — see
        // ResellerTaxPolicyService's class docblock.
        Auth::login($admin);

        $componentService = app(TaxComponentService::class);
        $policyService = app(ResellerTaxPolicyService::class);
        $calcService = app(TaxCalculationService::class);

        $periodStart = now()->startOfMonth();

        $ppn = $componentService->create([
            'code' => 'PPN', 'name' => 'PPN', 'type' => 'percentage', 'rate' => 11,
            'effective_from' => $periodStart->toDateString(),
            'description' => 'Pajak Pertambahan Nilai',
        ]);
        $bhpUso = $componentService->create([
            'code' => 'BHP_USO', 'name' => 'BHP USO', 'type' => 'fixed', 'rate' => 5000,
            'effective_from' => $periodStart->toDateString(),
            'description' => 'Biaya Hak Penyelenggaraan — Universal Service Obligation',
        ]);
        $pphBadan = $componentService->create([
            'code' => 'PPH_BADAN', 'name' => 'PPh Badan', 'type' => 'percentage', 'rate' => 2,
            'effective_from' => $periodStart->toDateString(),
            'description' => 'Pajak Penghasilan Badan',
        ]);

        // Direct-retail: every component customer_borne.
        foreach ([$ppn, $bhpUso, $pphBadan] as $component) {
            $policyService->setPolicy(null, $component, 'customer_borne', null, $periodStart);
        }

        // Reuse "Reseller Demo A" from v0.3.2 if it's already been seeded
        // (ResellerDemoSeeder) so this builds on the same demo dataset
        // instead of a disconnected one; create a second reseller either way.
        $resellerA = Reseller::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'reseller-demo-a'],
            ['name' => 'Reseller Demo A', 'status' => 'active']
        );
        $resellerB = Reseller::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'reseller-demo-b'],
            ['name' => 'Reseller Demo B', 'status' => 'active']
        );

        // Two resellers, deliberately different burden per Fase 5 spec.
        $policyService->setPolicy($resellerA, $ppn, 'customer_borne', null, $periodStart);
        $policyService->setPolicy($resellerB, $ppn, 'split', 40, $periodStart);
        // BHP_USO/PPh Badan have no reseller-specific override for either —
        // both fall back to the direct-retail policy set above (see
        // ResellerTaxPolicyService::getActivePolicies).

        $daysInMonth = $periodStart->daysInMonth;
        $targets = [null, $resellerA, $resellerB];
        $created = 0;
        $target = 0;

        while ($created < 20) {
            $reseller = $targets[$target % count($targets)];
            $target++;

            $amount = fake()->randomFloat(2, 300000, 1500000);
            $date = $periodStart->copy()->addDays(fake()->numberBetween(0, $daysInMonth - 1));

            $breakdown = $calcService->calculateForAmount($reseller, $amount, $date);
            $calcService->writeLedgerEntry($breakdown, $reseller, null, null, $date, 'seeded');

            $created++;
        }

        Auth::logout();

        $this->command?->info('== TaxEngineDummyDataSeeder selesai (LOCAL ONLY) ==');
        $this->command?->info('Tax components: PPN (11%), BHP_USO (Rp5.000 fixed), PPH_BADAN (2%)');
        $this->command?->info("Resellers: {$resellerA->name} (customer_borne), {$resellerB->name} (split 40% customer)");
        $this->command?->info("Ledger entries created: {$created} (source=seeded), tersebar di bulan ".$periodStart->translatedFormat('F Y'));
    }
}
