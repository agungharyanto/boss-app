<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_auto_derived_from_name_on_create(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Bajastu Teknologi Waringin']);

        $this->assertSame('BTW', $tenant->code);
    }

    public function test_an_explicitly_set_code_is_never_overridden(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Bajastu Teknologi Waringin', 'code' => 'CUSTOM']);

        $this->assertSame('CUSTOM', $tenant->code);
    }

    public function test_colliding_derived_code_gets_a_numeric_suffix(): void
    {
        Tenant::factory()->create(['name' => 'ISP Demo', 'code' => 'ID']);

        $second = Tenant::factory()->create(['name' => 'ISP Direct']);

        $this->assertSame('ID2', $second->code);
    }

    public function test_blank_name_leaves_code_null_instead_of_failing(): void
    {
        $tenant = Tenant::factory()->create(['name' => '   ']);

        $this->assertNull($tenant->code);
    }
}
