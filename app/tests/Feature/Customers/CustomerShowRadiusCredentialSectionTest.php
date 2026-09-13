<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerShow;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.12.2 — section "Kredensial PPPoE" di halaman Detail Pelanggan.
 */
class CustomerShowRadiusCredentialSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radcheck (id INTEGER PRIMARY KEY, username TEXT, attribute TEXT, op TEXT, value TEXT)
        ');
        DB::connection('radius')->statement('
            CREATE TABLE radreply (id INTEGER PRIMARY KEY, username TEXT, attribute TEXT, op TEXT, value TEXT)
        ');
    }

    private function admin(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_shows_username_password_toggle_and_framed_pool_when_credential_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '081229565701']);

        DB::connection('radius')->table('radcheck')->insert([
            'username' => '081229565701', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => '081229565701',
        ]);
        DB::connection('radius')->table('radreply')->insert([
            'username' => '081229565701', 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'HomeFixed-30Mbps',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('Kredensial PPPoE')
            ->assertSee('081229565701')
            ->assertSee('HomeFixed-30Mbps')
            ->assertSee('Aktif')
            ->assertSeeHtml('showPw');
    }

    public function test_shows_belum_di_freeradius_badge_when_no_credential_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '089900000000']);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('Kredensial PPPoE')
            ->assertSee('Belum di FreeRADIUS');
    }

    public function test_framed_pool_row_is_absent_when_no_framed_pool_stored(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone_number' => '081111111111']);

        DB::connection('radius')->table('radcheck')->insert([
            'username' => '081111111111', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => '081111111111',
        ]);

        Livewire::actingAs($this->admin($tenant))
            ->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('081111111111')
            ->assertDontSee('Framed-Pool');
    }
}
