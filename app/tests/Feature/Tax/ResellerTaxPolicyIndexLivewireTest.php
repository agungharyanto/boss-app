<?php

namespace Tests\Feature\Tax;

use App\Livewire\Tax\ResellerTaxPolicyIndex;
use App\Models\Reseller;
use App\Models\TaxComponent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ResellerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResellerTaxPolicyIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_set_direct_retail_policy_via_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('super_admin');
        $ppn = TaxComponent::factory()->for($tenant)->create();

        Livewire::actingAs($admin)
            ->test(ResellerTaxPolicyIndex::class)
            ->assertOk()
            ->set('targetResellerId', '')
            ->set('tax_component_id', (string) $ppn->id)
            ->set('burden', 'customer_borne')
            ->set('effective_from', now()->startOfMonth()->toDateString())
            ->call('createPolicy')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('reseller_tax_policies', [
            'reseller_id' => null,
            'tax_component_id' => $ppn->id,
            'burden' => 'customer_borne',
        ]);
    }

    public function test_reseller_owner_can_set_policy_for_own_reseller_via_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->for($tenant)->create();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $ppn = TaxComponent::factory()->for($tenant)->create();

        Livewire::withQueryParams([])
            ->actingAs($owner)
            ->test(ResellerTaxPolicyIndex::class)
            ->assertOk();

        // The Livewire component itself doesn't run HTTP middleware
        // (ResolveResellerContext), so bind the context directly here —
        // same technique as the plain-query isolation proof in
        // ResellerScopeIsolationTest.
        app(ResellerContext::class)->set($reseller);

        Livewire::actingAs($owner)
            ->test(ResellerTaxPolicyIndex::class)
            ->set('tax_component_id', (string) $ppn->id)
            ->set('burden', 'split')
            ->set('split_ratio', '30')
            ->set('effective_from', now()->startOfMonth()->toDateString())
            ->call('createPolicy')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('reseller_tax_policies', [
            'reseller_id' => $reseller->id,
            'tax_component_id' => $ppn->id,
            'burden' => 'split',
        ]);
    }
}
