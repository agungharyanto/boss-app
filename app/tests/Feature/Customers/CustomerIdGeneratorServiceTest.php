<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Services\CustomerIdGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CustomerIdGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_random_yyyymm_cid_for_a_direct_customer(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);

        // cid is already auto-assigned by CustomerObserver::created() at
        // the moment the factory inserts the row — no need (and no way,
        // without double-consuming randomness) to call generate() again.
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertMatchesRegularExpression('/^\d{10}$/', $customer->fresh()->cid);
        $this->assertStringStartsWith(now()->format('Ym'), $customer->fresh()->cid);
    }

    public function test_direct_customer_cids_are_not_sequential(): void
    {
        $tenant = Tenant::factory()->create();

        $first = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $second = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertNotSame($first->fresh()->cid, $second->fresh()->cid);
        // Neither one is the old sequential "{code}-000001" shape.
        $this->assertMatchesRegularExpression('/^\d{10}$/', $first->fresh()->cid);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $second->fresh()->cid);
    }

    public function test_direct_customer_cid_retries_on_a_random_collision(): void
    {
        $tenant = Tenant::factory()->create();
        $prefix = now()->format('Ym');

        $existing = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $existing->forceFill(['cid' => $prefix.'1234'])->saveQuietly();

        $service = new class extends CustomerIdGeneratorService
        {
            private int $calls = 0;

            protected function randomSuffix(): string
            {
                $this->calls++;

                // First candidate collides with $existing on purpose; the
                // second is free — proves generate() actually retries
                // instead of trusting the first random draw.
                return $this->calls === 1 ? '1234' : '5678';
            }
        };

        $newCustomer = Customer::factory()->make(['tenant_id' => $tenant->id]);
        $newCustomer->tenant_id = $tenant->id;

        $cid = $service->generate($newCustomer);

        $this->assertSame($prefix.'5678', $cid);
    }

    public function test_legacy_mixradius_member_id_short_circuits_the_generator_entirely(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'legacy_mixradius_member_id' => '251213587242',
        ]);

        $this->assertSame('251213587242', $customer->fresh()->cid);
    }

    public function test_legacy_mixradius_member_id_wins_even_when_reseller_id_is_set(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => $reseller->id,
            'legacy_mixradius_member_id' => '999888777',
        ]);

        $this->assertSame('999888777', $customer->fresh()->cid);
    }

    public function test_generates_cid_from_reseller_code_when_reseller_id_is_set(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bajastu Teknologi Waringin']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);

        $this->assertSame('BTW-000001', $customer->fresh()->cid);
    }

    public function test_sequence_number_increments_and_is_zero_padded_to_six_digits(): void
    {
        // Sequential numbering only still applies to reseller-attached
        // customers now — direct customers get the random YYYYMM format
        // above.
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'code' => 'BTW']);

        $first = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $second = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);

        $this->assertSame('BTW-000001', $first->fresh()->cid);
        $this->assertSame('BTW-000002', $second->fresh()->cid);
    }

    public function test_sequence_is_independent_per_tenant_even_with_the_same_code(): void
    {
        // tenants.code is globally unique, so two TENANTS can never share
        // one — the realistic way two different businesses end up with the
        // same short code is via reseller.code, which is only unique
        // *within* its own tenant.
        $tenantA = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenantA->id, 'code' => 'BTW']);

        $tenantB = Tenant::factory()->create();
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'BTW']);

        $customerA = Customer::factory()->create(['tenant_id' => $tenantA->id, 'reseller_id' => $resellerA->id]);
        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->id, 'reseller_id' => $resellerB->id]);

        $this->assertSame('BTW-000001', $customerA->fresh()->cid);
        $this->assertSame('BTW-000001', $customerB->fresh()->cid);
    }

    public function test_throws_when_reseller_has_no_code(): void
    {
        // Direct customers no longer need any code at all (random YYYYMM
        // format) — the only remaining "no code available" failure mode is
        // a reseller-attached customer whose reseller itself has no code
        // (blank name at creation time).
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => '   ']);
        $this->assertNull($reseller->fresh()->code);

        // CustomerObserver::created() already tried and caught this at
        // creation time (cid stays null, logged, customer still created
        // successfully) — calling the service directly here proves what it
        // caught really was this exception, not something else.
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $reseller->id]);
        $this->assertNull($customer->fresh()->cid);

        $this->expectException(RuntimeException::class);
        app(CustomerIdGeneratorService::class)->generate($customer);
    }
}
