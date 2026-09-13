<?php

namespace Database\Factories;

use App\Models\PppPackage;
use App\Models\WanConfigTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WanConfigTemplate>
 */
class WanConfigTemplateFactory extends Factory
{
    public function definition(): array
    {
        // Attribute order matters (Laravel resolves closure attributes in
        // ARRAY ORDER) — ppp_package_id first, tenant_id derived from it,
        // sama disiplin PppPackageFactory/CustomerIpPoolFactory.
        return [
            'ppp_package_id' => fn () => PppPackage::factory()->create()->id,
            'tenant_id' => fn (array $attributes) => PppPackage::withoutGlobalScopes()->find($attributes['ppp_package_id'])?->tenant_id,
            'modem_type_id' => null,
            'enabled' => false,
            'wan1_enabled' => true,
            'wan1_vlan' => 1000,
            'wan1_pppoe_username' => 'default',
            'wan1_pppoe_password' => 'default',
            'wan2_enabled' => false,
            'wan2_vlan' => 1200,
        ];
    }
}
