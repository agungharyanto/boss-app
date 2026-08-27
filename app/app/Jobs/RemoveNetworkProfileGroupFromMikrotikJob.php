<?php

namespace App\Jobs;

use App\Enums\NetworkProfileGroupType;
use App\Models\NetworkProfileGroup;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.3 — companion to PushNetworkProfileGroupToMikrotikJob, dispatched
 * by NetworkProfileGroupService::delete(). Ppp type removes the
 * `/ppp profile` object it created; Hotspot type does NOTHING to the
 * router — see the docblock on the Hotspot branch below for why.
 */
class RemoveNetworkProfileGroupFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $networkProfileGroupId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $group = NetworkProfileGroup::withoutGlobalScopes()->withTrashed()->with('nas')->find($this->networkProfileGroupId);

        if ($group === null || $group->nas === null) {
            Log::warning("RemoveNetworkProfileGroupFromMikrotikJob: NetworkProfileGroup #{$this->networkProfileGroupId} atau NAS terkait tidak ditemukan, dilewati.");

            return;
        }

        if ($group->type === NetworkProfileGroupType::Hotspot) {
            // Deliberately a no-op. This type's ONLY live-push effect was
            // ever setting address-pool= on a /ip hotspot SERVER instance
            // BOSS App does not own the lifecycle of (it must already
            // exist before any push is even attempted, per Agung's own
            // explicit decision — see RouterOsGateway::
            // syncHotspotServerPool()'s docblock). Blanking that field on
            // delete would risk breaking IP assignment for a router's real,
            // currently-active Hotspot clients — never attempted. Only the
            // boss_db row (already soft-deleted by the caller) and the
            // radgroupreply rows (removed synchronously by
            // NetworkProfileGroupService::delete() itself) are ever
            // cleaned up for this type.
            return;
        }

        $result = $gateway->removePppProfile($group->nas, $group->mikrotikComment());

        if ($result['success']) {
            return;
        }

        $this->recordFailure($group, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $group = NetworkProfileGroup::withoutGlobalScopes()->withTrashed()->find($this->networkProfileGroupId);

        $group?->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(NetworkProfileGroup $group, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $group->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.$reason]);

            return;
        }

        $delaySeconds = match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        };

        $this->release($delaySeconds);
    }
}
