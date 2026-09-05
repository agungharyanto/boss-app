<?php

namespace App\Jobs;

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
 * v0.14.5 — RouterOS live-push for PppPackage (Profil PPP).
 *
 * v0.14.5.4 (ATURAN KERAS 1:1 Grup Profil <-> Profil PPP, dikonfirmasi
 * Agung) — this job NO LONGER pushes a separate `/ppp profile` object.
 * Since one `/ppp profile` in RouterOS carries only ONE rate-limit, and a
 * Grup Profil is now hard-limited to ONE active Profil PPP, the two share a
 * SINGLE router object — keyed by the Grup Profil's OWN comment
 * ("BOSS App - Network Profile Group #N"). This job just UPDATES that shared
 * object via PppProfileSyncService::push(), adding this package's rate-limit
 * (from its BandwidthProfile) + session-timeout (from its Masa Aktif). A
 * deactivated package (`is_active = false`) resets the profile to bare
 * (PppProfileSyncService handles the is_active check internally).
 *
 * "Sync Ulang" from EITHER side (Grup Profil via PushNetworkProfileGroupToMikrotikJob,
 * or Profil PPP via this job) converges on the exact same router object.
 *
 * FIX 2 (v0.14.5.4) — PppProfileSyncService ensures the referenced `/ip
 * pool` genuinely exists on the router first (idempotent, lookup by
 * comment) before pushing the profile that references it.
 *
 * PppPackage::routerOsProfileName() (the old auto-differentiate helper) is
 * GONE — irrelevant now that there's never a second competing `/ppp
 * profile` object.
 */
class PushPppPackageToMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $pppPackageId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()
            ->with(['networkProfileGroup.nas', 'networkProfileGroup.customerIpPool', 'bandwidthProfile'])
            ->find($this->pppPackageId);

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null || $package->networkProfileGroup->customerIpPool === null) {
            Log::warning("PushPppPackageToMikrotikJob: PppPackage #{$this->pppPackageId}, Grup Profil, NAS, atau IP Pool terkait tidak ditemukan, dilewati.");

            return;
        }

        // BandwidthProfile hanya wajib untuk paket AKTIF (dipakai menghitung
        // rate-limit). Paket nonaktif -> profile Grup Profil di-reset ke bare,
        // tidak butuh bandwidth.
        if ($package->is_active && $package->bandwidthProfile === null) {
            Log::warning("PushPppPackageToMikrotikJob: PppPackage #{$this->pppPackageId} aktif tapi Bandwidth Profile-nya tidak ditemukan, dilewati.");

            return;
        }

        $group = $package->networkProfileGroup;

        $result = app(PppProfileSyncService::class)->push($gateway, $group, $package);

        if ($result['success']) {
            // Sapu objek `/ppp profile` per-paket PENINGGALAN v0.14.5.3
            // ("BOSS App - PPP Package #N", mungkin ber-suffix "(pkg #N)")
            // — sejak v0.14.5.4 Profil PPP tidak punya objek sendiri.
            // Idempoten no-op kalau memang tidak ada.
            $gateway->removePppProfile($group->nas, $package->mikrotikComment());

            $package->markSynced();
            $group->markSynced();

            return;
        }

        $group->markSyncFailed($result['message'] ?? 'Unknown failure');
        $this->recordFailure($package, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()->find($this->pppPackageId);

        $package?->markSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    /**
     * Same 30s/2min/5min backoff schedule as every other Mikrotik push Job
     * in this codebase.
     */
    private function recordFailure(PppPackage $package, string $reason): void
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
