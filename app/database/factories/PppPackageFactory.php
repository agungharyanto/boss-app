<?php

namespace Database\Factories;

use App\Enums\HotspotDurationUnit;
use App\Enums\NetworkProfileGroupType;
use App\Models\BandwidthProfile;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PppPackage>
 */
class PppPackageFactory extends Factory
{
    public function definition(): array
    {
        // Attribute order matters — same discipline as HotspotPackageFactory/
        // NetworkProfileGroupFactory (Laravel resolves closure attributes in
        // ARRAY ORDER): network_profile_group_id first (its own tenant is
        // the source of truth), then tenant_id derived from it, then
        // bandwidth_profile_id derived from tenant_id.
        return [
            'network_profile_group_id' => fn () => NetworkProfileGroup::factory()->create(['type' => NetworkProfileGroupType::Ppp])->id,
            'tenant_id' => fn (array $attributes) => NetworkProfileGroup::withoutGlobalScopes()->find($attributes['network_profile_group_id'])?->tenant_id,
            'bandwidth_profile_id' => fn (array $attributes) => BandwidthProfile::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => 'PPP-'.$this->faker->unique()->numerify('###'),
            'visible_to_reseller' => false,
            'cost_price' => 50000,
            'sell_price' => 100000,
            'promo_price' => null,
            'tax_percent' => 0,
            'active_duration_value' => 1,
            'active_duration_unit' => HotspotDurationUnit::Month,
            'shared_users' => 1,
            'priority' => 8,
            'login_days' => null,
            'login_start_time' => null,
            'login_end_time' => null,
            'is_active' => true,
        ];
    }

    /**
     * Masa Aktif = 0 (Unlimited / tanpa batas waktu — konvensi MixRadius).
     */
    public function unlimitedDuration(): static
    {
        return $this->state(fn () => ['active_duration_value' => 0]);
    }
}
