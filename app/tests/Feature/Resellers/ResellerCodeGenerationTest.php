<?php

namespace Tests\Feature\Resellers;

use App\Models\Reseller;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_auto_derived_from_name_on_create(): void
    {
        $reseller = Reseller::factory()->create(['name' => 'Bajastu Teknologi Waringin']);

        $this->assertSame('BTW', $reseller->code);
    }

    public function test_colliding_derived_code_within_the_same_tenant_gets_a_numeric_suffix(): void
    {
        $tenant = Tenant::factory()->create();

        Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bajastu Teknologi Waringin']);
        $second = Reseller::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Berkah Teknik Wireless']);

        $this->assertSame('BTW2', $second->code);
    }

    public function test_the_same_derived_code_is_allowed_across_different_tenants(): void
    {
        $first = Reseller::factory()->create(['name' => 'Bajastu Teknologi Waringin']);
        $second = Reseller::factory()->create(['name' => 'Bajastu Teknologi Waringin']);

        $this->assertSame('BTW', $first->code);
        $this->assertSame('BTW', $second->code);
        $this->assertNotSame($first->tenant_id, $second->tenant_id);
    }

    public function test_an_explicitly_set_code_is_never_overridden(): void
    {
        $reseller = Reseller::factory()->create(['name' => 'Bajastu Teknologi Waringin', 'code' => 'CUSTOM']);

        $this->assertSame('CUSTOM', $reseller->code);
    }
}
