<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PppPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'network_profile_group_id' => $this->network_profile_group_id,
            'network_profile_group_name' => $this->whenLoaded('networkProfileGroup', fn () => $this->networkProfileGroup->name),
            'nas_name' => $this->whenLoaded('networkProfileGroup', fn () => $this->networkProfileGroup->nas?->name),
            'bandwidth_profile_id' => $this->bandwidth_profile_id,
            'bandwidth_profile_name' => $this->whenLoaded('bandwidthProfile', fn () => $this->bandwidthProfile->name),
            'name' => $this->name,
            'visible_to_reseller' => $this->visible_to_reseller,
            'cost_price' => (float) $this->cost_price,
            'sell_price' => (float) $this->sell_price,
            'promo_price' => $this->promo_price !== null ? (float) $this->promo_price : null,
            'tax_percent' => (float) $this->tax_percent,
            'active_duration_value' => $this->active_duration_value,
            'active_duration_unit' => $this->active_duration_unit?->value,
            // 0 = Unlimited / tanpa batas waktu (session-timeout tidak
            // di-push ke RouterOS sama sekali).
            'is_unlimited_duration' => $this->isUnlimitedDuration(),
            'shared_users' => $this->shared_users,
            'priority' => $this->priority,
            'login_days' => $this->login_days,
            'login_start_time' => $this->login_start_time,
            'login_end_time' => $this->login_end_time,
            'is_active' => $this->is_active,
            'mikrotik_sync_status' => $this->mikrotik_sync_status?->value,
            'mikrotik_synced_at' => $this->mikrotik_synced_at?->toIso8601String(),
            'mikrotik_sync_error' => $this->mikrotik_sync_error,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
