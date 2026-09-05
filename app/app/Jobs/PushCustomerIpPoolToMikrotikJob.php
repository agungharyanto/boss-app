<?php

namespace App\Jobs;

use App\Enums\NetworkProfileGroupType;
use App\Models\CustomerIpPool;
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
 * v0.14.2.1 — RouterOS live-push, starting with CustomerIpPool (the first
 * entity in the "Profil Paket" cluster to get this capability — see
 * CLAUDE.md's "RouterOS Live-Push" section for the explicit plan to
 * generalize this to Bandwidth Profile/Grup Profil/Profil Hotspot/Profil
 * PPP in later sub-versions, NOT attempted here). Dispatched by
 * CustomerIpPoolService::create()/update() AFTER the row is already
 * committed to boss_db — never blocks the HTTP request on router
 * reachability, same "never let a slow/unreachable external device stall
 * a form submit" posture as SendWhatsappMessageJob.
 */
class PushCustomerIpPoolToMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Same retry count as SendWhatsappMessageJob — a reasonable default for a transient-failure-prone external call, not infinite. */
    public int $tries = 3;

    public function __construct(public readonly int $customerIpPoolId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        // withTrashed(): a re-dispatched/delayed attempt could in principle
        // land after the pool was soft-deleted in the meantime — still
        // worth completing the sync rather than silently no-op'ing, since
        // nothing else will correct the router's stale state for it.
        $pool = CustomerIpPool::withoutGlobalScopes()->withTrashed()->with('nas')->find($this->customerIpPoolId);

        if ($pool === null || $pool->nas === null) {
            Log::warning("PushCustomerIpPoolToMikrotikJob: CustomerIpPool #{$this->customerIpPoolId} atau NAS terkait tidak ditemukan, dilewati.");

            return;
        }

        $ranges = "{$pool->range_start}-{$pool->range_end}";

        // Bagian B (v0.14.5.4) — the name sent to the router auto-appends
        // " (pool)" when it collides with a `/ppp profile` name on the same
        // NAS (a real WinBox bug: RouterOS mis-resolves `remote-address`
        // when a pool and a profile share a name). The stable lookup key is
        // still the comment, so this rename-on-collision updates the
        // existing `/ip pool` in place.
        $result = $gateway->syncIpPool($pool->nas, $pool->mikrotikComment(), $pool->routerOsPoolName(), $ranges);

        if ($result['success']) {
            $pool->markSynced();

            // Any ppp-type Grup Profil referencing this pool has its
            // `/ppp profile` `remote-address` = the pool's (possibly
            // just-changed) router name — re-push so the reference follows.
            NetworkProfileGroup::query()
                ->withoutGlobalScopes()
                ->where('customer_ip_pool_id', $pool->id)
                ->where('type', NetworkProfileGroupType::Ppp->value)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->each(fn (int $id) => PushNetworkProfileGroupToMikrotikJob::dispatch($id));

            return;
        }

        $this->recordFailure($pool, $result['message'] ?? 'Unknown failure');
    }

    /**
     * Guaranteed final state even if something throws before/outside the
     * handle() body (e.g. a serialization bug) exhausts all retries — same
     * posture as SendWhatsappMessageJob::failed().
     */
    public function failed(?Throwable $exception): void
    {
        $pool = CustomerIpPool::withoutGlobalScopes()->withTrashed()->find($this->customerIpPoolId);

        $pool?->markSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    /**
     * Non-final attempt: release back onto its own queue with an
     * exponential delay (30s / 2min / 5min — same schedule already
     * established by SendWhatsappMessageJob) and leave status=pending for
     * the next try. Final attempt: mark failed, no further release.
     */
    private function recordFailure(CustomerIpPool $pool, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $pool->markSyncFailed($reason);

            return;
        }

        $pool->update(['mikrotik_sync_error' => $reason]);

        $delaySeconds = match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        };

        $this->release($delaySeconds);
    }
}
