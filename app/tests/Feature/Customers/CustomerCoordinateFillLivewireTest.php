<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerCoordinateFill;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCoordinateFillLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_lists_only_customers_without_coordinates(): void
    {
        $tenant = Tenant::factory()->create();
        $missing = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tanpa Koordinat', 'latitude' => null, 'longitude' => null]);
        $partial = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Setengah', 'latitude' => -6.2, 'longitude' => null]);
        $done = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sudah Lengkap', 'latitude' => -6.2, 'longitude' => 106.8]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerCoordinateFill::class)
            ->assertSee('Tanpa Koordinat')
            ->assertSee('Setengah')   // one coord filled still counts as incomplete
            ->assertDontSee('Sudah Lengkap');
    }

    public function test_search_matches_name_cid_and_phone(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budi Santoso', 'phone_number' => '081200001111', 'latitude' => null, 'longitude' => null])->forceFill(['cid' => '90001'])->save();
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Siti Aminah', 'phone_number' => '081200002222', 'latitude' => null, 'longitude' => null])->forceFill(['cid' => '90002'])->save();

        $component = Livewire::actingAs($this->admin($tenant))->test(CustomerCoordinateFill::class);

        $component->set('search', 'Budi')->assertSee('Budi Santoso')->assertDontSee('Siti Aminah');
        $component->set('search', '90002')->assertSee('Siti Aminah')->assertDontSee('Budi Santoso');
        $component->set('search', '081200001111')->assertSee('Budi Santoso')->assertDontSee('Siti Aminah');
    }

    public function test_set_location_saves_only_the_coordinates_and_creates_no_odp_relation(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Pak Har',
            'address' => 'Jl. Melati 3',
            'latitude' => null,
            'longitude' => null,
        ]);
        $originalStatus = $customer->status;

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerCoordinateFill::class)
            ->call('startEditing', $customer->id)
            ->assertSet('editingCustomerId', $customer->id)
            ->assertSet('latitude', '')
            ->set('latitude', '-7.5378223')
            ->set('longitude', '108.8025558')
            ->call('saveLocation')
            ->assertHasNoErrors()
            ->assertSet('editingCustomerId', null);

        $customer->refresh();
        $this->assertEqualsWithDelta(-7.5378223, (float) $customer->latitude, 0.0000001);
        $this->assertEqualsWithDelta(108.8025558, (float) $customer->longitude, 0.0000001);
        // nothing else on the customer moved
        $this->assertSame($originalStatus, $customer->status);
        $this->assertSame('Jl. Melati 3', $customer->address);

        // EXPLICIT: no ODP link / work order / port reservation was created
        $this->assertSame(0, WorkOrder::count());
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('sales_route_notes', 0);
        $this->assertDatabaseCount('odp_ports', 0);
        $this->assertSame(0, $customer->workOrders()->count());
    }

    public function test_saved_customer_drops_off_the_list(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Hilang Setelah Simpan', 'latitude' => null, 'longitude' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerCoordinateFill::class)
            ->assertViewHas('customers', fn ($c) => $c->contains('id', $customer->id))
            ->call('startEditing', $customer->id)
            ->set('latitude', '-6.2')
            ->set('longitude', '106.8')
            ->call('saveLocation')
            ->assertViewHas('customers', fn ($c) => ! $c->contains('id', $customer->id));
    }

    public function test_save_location_validates_the_coordinate_range(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerCoordinateFill::class)
            ->call('startEditing', $customer->id)
            ->set('latitude', '999')
            ->set('longitude', 'abc')
            ->call('saveLocation')
            ->assertHasErrors(['latitude', 'longitude']);

        $this->assertNull($customer->fresh()->latitude);
    }

    public function test_a_view_only_user_cannot_save_a_location(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);
        $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
        $viewer->givePermissionTo('customers.view');

        Livewire::actingAs($viewer)
            ->test(CustomerCoordinateFill::class)
            ->assertOk()
            ->assertViewHas('canManage', false)
            ->call('startEditing', $customer->id)
            ->assertForbidden();
    }

    public function test_a_user_without_customers_view_cannot_mount(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]); // no role, no permission

        Livewire::actingAs($user)
            ->test(CustomerCoordinateFill::class)
            ->assertForbidden();
    }
}
