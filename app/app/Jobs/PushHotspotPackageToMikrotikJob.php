<?php

namespace App\Jobs;

use App\Models\HotspotPackage;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.4 — RouterOS live-push for HotspotPackage (Profil Hotspot), reusing
 * PushCustomerIpPoolToMikrotikJob/PushNetworkProfileGroupToMikrotikJob's
 * exact async/retry/backoff shape (v0.14.2.1/v0.14.3) rather than
 * reinventing it. Pushes to `/ip hotspot user profile` — a genuinely
 * different RouterOS object than Grup Profil's own Hotspot-type push
 * (which only ever touches the shared `/ip hotspot` SERVER's address-pool)
 * — see RouterOsGateway::syncHotspotUserProfile()'s own docblock.
 */
class PushHotspotPackageToMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $hotspotPackageId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $package = HotspotPackage::withoutGlobalScopes()->withTrashed()
            ->with(['networkProfileGroup.nas', 'networkProfileGroup.customerIpPool', 'bandwidthProfile'])
            ->find($this->hotspotPackageId);

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null || $package->networkProfileGroup->customerIpPool === null || $package->bandwidthProfile === null) {
            Log::warning("PushHotspotPackageToMikrotikJob: HotspotPackage #{$this->hotspotPackageId}, Grup Profil, NAS, IP Pool, atau Bandwidth Profile terkait tidak ditemukan, dilewati.");

            return;
        }

        $nas = $package->networkProfileGroup->nas;
        $bandwidth = $package->bandwidthProfile;
        $rateLimit = "{$bandwidth->upload_max}k/{$bandwidth->download_max}k";
        // The `/ip pool` name a Profil Hotspot actually gets its clients'
        // IP from — resolved via its own Grup Profil (v0.14.3), same
        // relation Grup Profil's own PPP push already uses for
        // remote-address. CustomerIpPool's OWN live-push (v0.14.2.1) always
        // keeps the router-side pool's name in sync with ->name on every
        // successful sync (comment-based lookup, unlike this object type —
        // see RouterOsGateway::syncHotspotUserProfile()'s own docblock), so
        // this is always the current real router-side name.
        $addressPool = $package->networkProfileGroup->customerIpPool->name;

        $result = $gateway->syncHotspotUserProfile(
            $nas,
            $package->mikrotikLookupName(),
            $package->mikrotikTargetName(),
            $rateLimit,
            $package->shared_users,
            $package->routerOsSessionTimeout(),
            $addressPool,
        );

        if ($result['success']) {
            $package->markSynced();

            return;
        }

        $message = $result['message'] ?? 'Unknown failure';

        // Same reasoning as PushNetworkProfileGroupToMikrotikJob's own
        // missing-Hotspot-Server handling — a permanent config problem, not
        // a transient network hiccup, so retrying 3x with backoff before
        // showing the admin the real, actionable error would be needlessly
        // slow. Detected by the exact stable message text
        // RouterOsGateway::syncHotspotUserProfile() always returns for
        // this case.
        if (str_contains($message, 'belum punya Hotspot Server')) {
            $package->markSyncFailed($message);

            return;
        }

        $this->recordFailure($package, $message);
    }

    public function failed(?Throwable $exception): void
    {
        $package = HotspotPackage::withoutGlobalScopes()->withTrashed()->find($this->hotspotPackageId);

        $package?->markSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    /**
     * Same 30s/2min/5min backoff schedule as every other Mikrotik push Job
     * in this codebase.
     */
    private function recordFailure(HotspotPackage $package, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $package->markSyncFailed($reason);

            return;
        }

        $package->update(['mikrotik_sync_error' => $reason]);

        $delaySeconds = match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        };

        $this->release($delaySeconds);
    }
}
