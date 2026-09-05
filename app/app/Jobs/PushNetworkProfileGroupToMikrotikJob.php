<?php

namespace App\Jobs;

use App\Enums\NetworkProfileGroupType;
use App\Models\NetworkProfileGroup;
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
 * v0.14.3 — RouterOS live-push for NetworkProfileGroup (Grup Profil),
 * reusing PushCustomerIpPoolToMikrotikJob's exact async/retry/backoff
 * shape (v0.14.2.1) rather than reinventing it.
 *
 * Branches by type because the two RouterOS targets are genuinely
 * different entities, confirmed empirically before implementing (see
 * RouterOsGateway's own docblocks):
 * - Ppp: `/ppp profile` — assembled + pushed by PppProfileSyncService
 *   (v0.14.5.4), SHARED with PushPppPackageToMikrotikJob (one router
 *   object per Grup Profil, keyed by the Grup Profil's comment). This job
 *   passes the group's ONE active Profil PPP (or null -> bare fallback
 *   profile) so a group create/edit/resync re-derives the rate-limit from
 *   whatever package currently occupies it.
 * - Hotspot: `/ip hotspot user profile` has NO pool/dns/parent-queue
 *   fields at all — the only real RouterOS effect for this type is
 *   updating the NAS's existing `/ip hotspot` SERVER instance's own
 *   `address-pool`, which requires that server to already exist (Agung's
 *   explicit decision: refuse with a clear error rather than silently
 *   no-op or invent a server on the admin's behalf).
 */
class PushNetworkProfileGroupToMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $networkProfileGroupId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $group = NetworkProfileGroup::withoutGlobalScopes()->withTrashed()
            ->with(['nas', 'customerIpPool', 'activePppPackage.bandwidthProfile'])
            ->find($this->networkProfileGroupId);

        if ($group === null || $group->nas === null || $group->customerIpPool === null) {
            Log::warning("PushNetworkProfileGroupToMikrotikJob: NetworkProfileGroup #{$this->networkProfileGroupId}, NAS, atau CustomerIpPool terkait tidak ditemukan, dilewati.");

            return;
        }

        $result = $group->type === NetworkProfileGroupType::Ppp
            ? app(PppProfileSyncService::class)->push($gateway, $group, $group->activePppPackage)
            : $gateway->syncHotspotServerPool($group->nas, $group->customerIpPool->routerOsPoolName());

        if ($result['success']) {
            $group->markSynced();

            return;
        }

        $message = $result['message'] ?? 'Unknown failure';

        // A missing Hotspot Server (see RouterOsGateway::syncHotspotServerPool's
        // own docblock) is a permanent config problem, not a transient
        // network hiccup — retrying 3x with backoff (~7.5 minutes) before
        // showing the admin the real, actionable error would be needlessly
        // slow for something retrying can never fix. Detected by the exact
        // stable message text that method always returns for this case.
        if (str_contains($message, 'belum punya Hotspot Server')) {
            $group->markSyncFailed($message);

            return;
        }

        $this->recordFailure($group, $message);
    }

    public function failed(?Throwable $exception): void
    {
        $group = NetworkProfileGroup::withoutGlobalScopes()->withTrashed()->find($this->networkProfileGroupId);

        $group?->markSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    /**
     * Same 30s/2min/5min backoff schedule as PushCustomerIpPoolToMikrotikJob
     * — for genuinely transient failures (network timeout, router briefly
     * unreachable). The one known permanent-failure case (missing Hotspot
     * Server) is intercepted before this ever runs, see handle() above.
     */
    private function recordFailure(NetworkProfileGroup $group, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $group->markSyncFailed($reason);

            return;
        }

        $group->update(['mikrotik_sync_error' => $reason]);

        $delaySeconds = match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        };

        $this->release($delaySeconds);
    }
}
