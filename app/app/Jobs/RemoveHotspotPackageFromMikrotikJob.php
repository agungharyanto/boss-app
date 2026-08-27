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
 * v0.14.4 — companion to PushHotspotPackageToMikrotikJob, dispatched by
 * HotspotPackageService::delete(). UNLIKE RemoveNetworkProfileGroupFromMikrotikJob's
 * Hotspot-type branch (a deliberate no-op — that push only ever touches a
 * shared, admin-owned `/ip hotspot` SERVER object), a HotspotPackage's own
 * `/ip hotspot user profile` object is fully BOSS-App-created — safe, and
 * correct, to actually remove it.
 */
class RemoveHotspotPackageFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $hotspotPackageId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $package = HotspotPackage::withoutGlobalScopes()->withTrashed()->with('networkProfileGroup.nas')->find($this->hotspotPackageId);

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null) {
            Log::warning("RemoveHotspotPackageFromMikrotikJob: HotspotPackage #{$this->hotspotPackageId}, Grup Profil, atau NAS terkait tidak ditemukan, dilewati.");

            return;
        }

        $result = $gateway->removeHotspotUserProfile($package->networkProfileGroup->nas, $package->mikrotikLookupName());

        if ($result['success']) {
            return;
        }

        $this->recordFailure($package, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $package = HotspotPackage::withoutGlobalScopes()->withTrashed()->find($this->hotspotPackageId);

        $package?->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(HotspotPackage $package, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $package->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.$reason]);

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
