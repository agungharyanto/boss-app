<?php

namespace App\Jobs;

use App\Models\Nas;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Revisi Grup Profil (Langkah 3) — RouterOS live-push for a NAS's own
 * "Profile Pelanggan Expired" fallback `/ppp profile`, reusing the exact
 * same async/retry/backoff shape as PushNetworkProfileGroupToMikrotikJob
 * (v0.14.3) rather than reinventing it.
 *
 * Pushes via the SAME syncPppProfile() method Grup Profil's own PPP push
 * already uses (widened in this same revision to accept a nullable
 * remoteAddress + an optional localAddress specifically for this use
 * case) — `remote-address` deliberately null (RouterOS rejects an empty
 * string for this field, confirmed empirically, so it must be OMITTED
 * entirely, not sent blank), `local-address` set to the chosen
 * CustomerIpPool's own name, no dns-server/parent-queue (Agung's own
 * real Winbox pattern: an expired-customer fallback profile has no
 * rate-limit at all, only a restricted pool).
 */
class PushExpiredProfileToMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $nasId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $nas = Nas::with('expiredIpPool')->find($this->nasId);

        if ($nas === null || $nas->expiredIpPool === null) {
            Log::warning("PushExpiredProfileToMikrotikJob: NAS #{$this->nasId} atau expired_ip_pool_id terkait tidak ditemukan, dilewati.");

            return;
        }

        $result = $gateway->syncPppProfile(
            $nas,
            $nas->expiredProfileMikrotikComment(),
            $nas->expiredProfileMikrotikName(),
            null,
            null,
            null,
            $nas->expiredIpPool->name,
        );

        if ($result['success']) {
            $nas->markExpiredProfileSynced();

            return;
        }

        $this->recordFailure($nas, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $nas = Nas::find($this->nasId);

        $nas?->markExpiredProfileSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    /**
     * Same 30s/2min/5min backoff schedule as PushNetworkProfileGroupToMikrotikJob
     * — reused verbatim, not reinvented.
     */
    private function recordFailure(Nas $nas, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $nas->markExpiredProfileSyncFailed($reason);

            return;
        }

        $nas->update(['expired_profile_mikrotik_sync_error' => $reason]);

        $delaySeconds = match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        };

        $this->release($delaySeconds);
    }
}
