<?php

namespace Database\Factories;

use App\Enums\OltAccessProtocol;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OltDevice>
 */
class OltDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Derived from the parent NAS's own tenant_id — never an
            // independent random tenant, same "avoid inconsistent
            // cross-tenant fixtures" reasoning as CustomerContactFactory
            // (see CLAUDE.md's multi-tenancy section).
            'tenant_id' => fn (array $attributes) => Nas::withoutGlobalScopes()->find($attributes['nas_id'])?->tenant_id,
            'reseller_id' => null,
            'name' => 'OLT-'.$this->faker->unique()->numerify('###'),
            'nas_id' => Nas::factory(),
            'ip_address' => '10.'.$this->faker->numberBetween(0, 255).'.'.$this->faker->numberBetween(0, 255).'.'.$this->faker->numberBetween(1, 254),
            'olt_model_id' => OltModel::factory(),
            // SNMP is always-on now (addendum #2), independent of
            // access_protocol — Telnet is just an arbitrary default CLI
            // access choice here, SNMP fields are filled regardless.
            'access_protocol' => OltAccessProtocol::Telnet,
            'telnet_port' => 2333,
            'telnet_username' => 'admin',
            'snmp_version' => 'v2c',
            'snmp_port' => 2161,
            'snmp_ro_community' => $this->faker->password(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
