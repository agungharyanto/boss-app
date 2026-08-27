<?php

namespace Database\Factories;

use App\Models\CustomerIpPool;
use App\Models\Nas;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerIpPool>
 */
class CustomerIpPoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A distinct third octet per row keeps sibling factory-made pools
        // from accidentally overlapping unless a test deliberately wants
        // that (see the overlap regression tests, which build ranges by
        // hand instead of relying on this default).
        $octet = $this->faker->unique()->numberBetween(10, 250);

        return [
            // nas_id MUST be declared before tenant_id below — Laravel's
            // factory attribute resolution processes the definition() array
            // in order, so an EARLIER key is already resolved to its final
            // scalar by the time a LATER closure's $attributes argument is
            // built, but a key declared AFTER the closure is still the raw
            // Factory/unresolved value. CustomerContactFactory (customer_id
            // before tenant_id) already relies on this and works; the
            // pre-existing OltDeviceFactory has tenant_id BEFORE nas_id and
            // is consequently broken on a bare ::factory()->create() with no
            // explicit nas_id override (confirmed directly — not touched
            // here, out of this sprint's scope, but don't copy that order).
            'nas_id' => Nas::factory(),
            // Derived from the parent NAS's own tenant_id — never an
            // independent random tenant, same "avoid inconsistent
            // cross-tenant fixtures" reasoning as CustomerContactFactory/
            // OltDeviceFactory (see CLAUDE.md's multi-tenancy section).
            'tenant_id' => fn (array $attributes) => Nas::withoutGlobalScopes()->find($attributes['nas_id'])?->tenant_id,
            'name' => 'Pool-'.$this->faker->unique()->numerify('###'),
            'network_address' => "192.168.{$octet}.0/24",
            'gateway_ip' => "192.168.{$octet}.1",
            'range_start' => "192.168.{$octet}.10",
            'range_end' => "192.168.{$octet}.200",
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
            'is_active' => true,
        ];
    }
}
