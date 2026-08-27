<?php

namespace Database\Factories;

use App\Enums\HotspotDurationUnit;
use App\Enums\HotspotLimitType;
use App\Enums\HotspotProfileType;
use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\HotspotPackage;
use App\Models\NetworkProfileGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotspotPackage>
 */
class HotspotPackageFactory extends Factory
{
    public function definition(): array
    {
        // Attribute order matters — see NetworkProfileGroupFactory's own
        // docblock (Laravel resolves closure attributes in ARRAY ORDER).
        // network_profile_group_id first (its own tenant is the source of
        // truth), then tenant_id derived from it, then bandwidth_profile_id
        // derived from tenant_id — never an independent random tenant for
        // either FK, same discipline as CustomerContactFactory/
        // NetworkProfileGroupFactory.
        return [
            'network_profile_group_id' => fn () => NetworkProfileGroup::factory()->create(['type' => NetworkProfileGroupType::Hotspot])->id,
            'tenant_id' => fn (array $attributes) => NetworkProfileGroup::withoutGlobalScopes()->find($attributes['network_profile_group_id'])?->tenant_id,
            'bandwidth_profile_id' => fn (array $attributes) => BandwidthProfile::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => 'Paket-'.$this->faker->unique()->numerify('###'),
            'visible_to_reseller' => false,
            'show_in_voucher_form' => false,
            'cost_price' => 2000,
            'sell_price' => 5000,
            'promo_price' => null,
            'tax_percent' => 0,
            'profile_type' => HotspotProfileType::Unlimited,
            'limit_type' => null,
            'active_duration_value' => null,
            'active_duration_unit' => null,
            'shared_users' => 1,
            'priority' => 'Default',
            'login_days' => null,
            'login_start_time' => null,
            'login_end_time' => null,
            'is_active' => true,
        ];
    }

    public function limited(): static
    {
        return $this->state(fn () => [
            'profile_type' => HotspotProfileType::Limited,
            'limit_type' => HotspotLimitType::TimeBase,
            'active_duration_value' => 1,
            'active_duration_unit' => HotspotDurationUnit::Day,
        ]);
    }
}
