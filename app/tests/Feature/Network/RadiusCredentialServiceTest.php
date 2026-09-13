<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Services\Network\RadiusCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v0.12.2 — "Kredensial PPPoE" section, Detail Pelanggan. Sama pola
 * isolasi `radius` connection (sqlite in-memory, tabel dibuat manual —
 * radcheck/radreply skema FreeRADIUS, bukan migration Laravel) dengan
 * RadiusSessionHistoryServiceTest.
 */
class RadiusCredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radcheck (
                id INTEGER PRIMARY KEY,
                username TEXT,
                attribute TEXT,
                op TEXT,
                value TEXT
            )
        ');
        DB::connection('radius')->statement('
            CREATE TABLE radreply (
                id INTEGER PRIMARY KEY,
                username TEXT,
                attribute TEXT,
                op TEXT,
                value TEXT
            )
        ');
    }

    private function insertRadcheck(string $username, string $password): void
    {
        DB::connection('radius')->table('radcheck')->insert([
            'username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password,
        ]);
    }

    private function insertRadreply(string $username, string $attribute, string $value): void
    {
        DB::connection('radius')->table('radreply')->insert([
            'username' => $username, 'attribute' => $attribute, 'op' => ':=', 'value' => $value,
        ]);
    }

    public function test_returns_credential_matched_by_phone_number(): void
    {
        $this->insertRadcheck('081229565701', '081229565701');
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $result = app(RadiusCredentialService::class)->lookupForCustomer($customer);

        $this->assertNotNull($result);
        $this->assertSame('081229565701', $result['username']);
        $this->assertSame('081229565701', $result['password']);
        $this->assertTrue($result['enabled']);
        $this->assertNull($result['framed_pool']);
    }

    public function test_falls_back_to_legacy_username_when_it_differs_from_phone_number(): void
    {
        $this->insertRadcheck('legacy-name-123', 'legacy-name-123');
        $customer = Customer::factory()->create(['phone_number' => '089900001111', 'legacy_username' => 'legacy-name-123']);

        $result = app(RadiusCredentialService::class)->lookupForCustomer($customer);

        $this->assertNotNull($result);
        $this->assertSame('legacy-name-123', $result['username']);
    }

    public function test_includes_framed_pool_when_present_in_radreply(): void
    {
        $this->insertRadcheck('081229565701', '081229565701');
        $this->insertRadreply('081229565701', 'Service-Type', 'Framed-User');
        $this->insertRadreply('081229565701', 'Framed-Protocol', 'PPP');
        $this->insertRadreply('081229565701', 'Framed-Pool', 'HomeFixed-30Mbps');
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $result = app(RadiusCredentialService::class)->lookupForCustomer($customer);

        $this->assertSame('HomeFixed-30Mbps', $result['framed_pool']);
    }

    public function test_customer_with_no_radcheck_row_returns_null_not_an_error(): void
    {
        $customer = Customer::factory()->create(['phone_number' => '000000000000', 'legacy_username' => null]);

        $result = app(RadiusCredentialService::class)->lookupForCustomer($customer);

        $this->assertNull($result);
    }

    public function test_customer_with_no_candidate_username_at_all_returns_null(): void
    {
        $customer = Customer::factory()->create(['phone_number' => '', 'legacy_username' => null]);

        $result = app(RadiusCredentialService::class)->lookupForCustomer($customer);

        $this->assertNull($result);
    }
}
