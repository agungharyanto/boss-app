<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerIndex;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']));

        return $user;
    }

    public function test_search_by_name_is_case_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ani Wijaya']);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->set('search', 'BUDI santoso')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Ani Wijaya');
    }

    public function test_search_by_phone_number_is_case_insensitive_and_partial(): void
    {
        // phone_number is all-digits — this proves whereRaw(LOWER(...))
        // still does a partial LIKE match, not just casing.
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '081234567890']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '089988877766']);

        Livewire::actingAs($this->viewer($tenant))
            ->test(CustomerIndex::class)
            ->set('search', '81234567')
            ->assertSee('081234567890')
            ->assertDontSee('089988877766');
    }
}
