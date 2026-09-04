<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sprint "perpanjang-daftar-pelanggan" LANGKAH 1 — akses terbatas Referrer
 * murni ke Daftar Pelanggan (dan HANYA route itu).
 */
class CustomerListReferrerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::factory()->create();
    }

    private function referrerOnlyUser(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return $user;
    }

    private function adminUser(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));

        return $user;
    }

    public function test_referrer_only_user_can_open_the_customer_list_in_the_stripped_down_view(): void
    {
        $tenant = $this->tenant();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Pelanggan Uji']);

        $this->actingAs($this->referrerOnlyUser($tenant))
            ->get('/customers')
            ->assertOk()
            ->assertSee('Pelanggan Uji')
            ->assertSee('Perpanjang')
            ->assertDontSee('Registrasi Pelanggan')
            ->assertDontSee('+ Pelanggan Baru')
            ->assertSee('Portal Referrer'); // rendered under layouts.referrer-portal, no admin sidebar
    }

    public function test_referrer_only_user_is_blocked_from_every_other_admin_page(): void
    {
        $tenant = $this->tenant();
        $user = $this->referrerOnlyUser($tenant);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/customers/'.$customer->id)->assertForbidden();
        $this->actingAs($user)->get('/customers/register')->assertForbidden();
        $this->actingAs($user)->get('/nas')->assertForbidden();
        $this->actingAs($user)->get('/invoices')->assertForbidden();
    }

    public function test_inactive_referrer_link_does_not_grant_access(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Referrer::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $this->actingAs($user)->get('/customers')->assertForbidden();
    }

    public function test_a_user_who_is_neither_admin_nor_referrer_is_forbidden(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get('/customers')->assertForbidden();
    }

    public function test_staff_with_admin_access_still_sees_the_full_list_plus_the_renew_button(): void
    {
        $tenant = $this->tenant();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Pelanggan Admin']);

        $this->actingAs($this->adminUser($tenant))
            ->get('/customers')
            ->assertOk()
            ->assertSee('Pelanggan Admin')
            ->assertSee('Perpanjang')
            ->assertSee('Detail');
    }
}
