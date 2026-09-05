<?php

namespace App\Jobs;

use App\Enums\NetworkProfileGroupType;
use App\Models\PppPackage;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Services\Network\PppProfileSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.5 — companion to PushPppPackageToMikrotikJob, dispatched by
 * PppPackageService::delete().
 *
 * v0.14.5.4 (ATURAN KERAS 1:1) — a Profil PPP no longer has its OWN `/ppp
 * profile` object to delete; it shares the Grup Profil's one. So removing a
 * Profil PPP RE-PUSHES the Grup Profil's `/ppp profile` in BARE mode
 * (PppProfileSyncService::push() with $package = null), which actively
 * CLEARS the rate-limit + session-timeout off the router
 * (RouterOsGateway::syncPppProfile()'s SET branch sends `rate-limit=""` /
 * `session-timeout="0s"` when null).
 *
 * WHY reset rather than leave the stale cap (poin 4 dari brief, keputusan
 * Agung): that `/ppp profile` doubles as the PPPoE Server's Default Profile
 * — the fallback for any session RADIUS doesn't hand a specific profile to.
 * A leftover rate-limit from a deleted package would silently throttle
 * every unclassified session at a bandwidth that no longer maps to any
 * sellable package. Bare = the fallback stays pure network config
 * (pool/dns/gateway) with no bandwidth cap.
 */
class RemovePppPackageFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $pppPackageId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()
            ->with(['networkProfileGroup.nas', 'networkProfileGroup.customerIpPool'])
            ->find($this->pppPackageId);

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null || $package->networkProfileGroup->customerIpPool === null) {
            Log::warning("RemovePppPackageFromMikrotikJob: PppPackage #{$this->pppPackageId}, Grup Profil, NAS, atau IP Pool terkait tidak ditemukan, dilewati.");

            return;
        }

        $group = $package->networkProfileGroup;

        // The Grup Profil could itself be soft-deleted / non-ppp (a cascade
        // delete of the parent) — nothing to reset on the router then.
        if ($group->deleted_at !== null || $group->type !== NetworkProfileGroupType::Ppp) {
            return;
        }

        // Sapu objek `/ppp profile` per-paket PENINGGALAN v0.14.5.3, kalau
        // masih ada (idempoten no-op kalau tidak).
        $gateway->removePppProfile($group->nas, $package->mikrotikComment());

        $result = app(PppProfileSyncService::class)->push($gateway, $group, null);

        if ($result['success']) {
            $group->markSynced();

            return;
        }

        $this->recordFailure($package, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()->find($this->pppPackageId);

        $package?->update(['mikrotik_sync_error' => 'Gagal reset profil Grup Profil di router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(PppPackage $package, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $package->update(['mikrotik_sync_error' => 'Gagal reset profil Grup Profil di router: '.$reason]);

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
