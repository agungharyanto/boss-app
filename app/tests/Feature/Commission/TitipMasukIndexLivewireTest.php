<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Livewire\Commission\TitipMasukIndex;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TitipMasukIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant, string $role = 'superadmin'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function titipRow(Tenant $tenant, string $customerName, CommissionStatus $status = CommissionStatus::Eligible): CommissionLedger
    {
        $referrer = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'name' => $customerName,
            'referred_by_referrer_id' => $referrer->id,
        ]);

        return CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id,
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => $status,
            'amount' => 3000,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);
    }

    public function test_lists_only_titip_scheme_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Pelanggan Titip');

        // A non-titip row must not appear.
        $r = Referrer::factory()->create(['tenant_id' => $tenant->id]);
        $c = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'name' => 'Pelanggan Recurring']);
        CommissionLedger::factory()->create([
            'tenant_id' => $tenant->id, 'referrer_id' => $r->id, 'customer_id' => $c->id,
            'scheme' => CommissionScheme::Recurring->value, 'status' => CommissionStatus::Eligible, 'amount' => 5000,
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->assertSee('Pelanggan Titip')
            ->assertDontSee('Pelanggan Recurring');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Yang Eligible', CommissionStatus::Eligible);
        $this->titipRow($tenant, 'Yang Paid', CommissionStatus::Paid);

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('statusFilter', CommissionStatus::Paid->value)
            ->assertSee('Yang Paid')
            ->assertDontSee('Yang Eligible');
    }

    public function test_search_matches_referrer_or_customer_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->titipRow($tenant, 'Bambang Sudibyo');
        $this->titipRow($tenant, 'Citra Lestari');

        Livewire::actingAs($this->admin($tenant))
            ->test(TitipMasukIndex::class)
            ->set('search', 'Bambang')
            ->assertSee('Bambang Sudibyo')
            ->assertDontSee('Citra Lestari');
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $plain = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($plain)
            ->test(TitipMasukIndex::class)
            ->assertForbidden();
    }

    public function test_route_and_sidebar_link_are_reachable_for_an_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);

        $this->actingAs($admin)->get('/titip-masuk')->assertOk();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('web.titip-masuk.index'));
    }
}
