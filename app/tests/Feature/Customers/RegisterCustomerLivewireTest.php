<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\RegisterCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterCustomerLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_duplicate_nik_is_rejected_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');

        Customer::factory()->create([
            'tenant_id' => $user->tenant_id,
            'nik' => '3201012501990001',
        ]);

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Nama Lain')
            ->set('address', 'Jl. Merdeka No. 2')
            ->set('phone_number', '081234567899')
            ->set('nik', '3201012501990001')
            ->call('register')
            ->assertHasErrors(['nik']);

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_fresh_nik_is_accepted_via_the_livewire_registration_form(): void
    {
        $user = $this->userWithRole('sales_internal');

        $this->actingAs($user);

        Livewire::test(RegisterCustomer::class)
            ->set('name', 'Pelanggan Baru')
            ->set('address', 'Jl. Merdeka No. 3')
            ->set('phone_number', '081234567898')
            ->set('nik', '3201012501990002')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru']);
    }
}
