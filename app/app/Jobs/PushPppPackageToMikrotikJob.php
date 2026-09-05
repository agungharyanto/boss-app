<?php

namespace App\Jobs;

use App\Models\PppPackage;
use App\Services\Network\Contracts\RouterOsGateway;
use App\Support\RouterOsQueuePriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.5 — RouterOS live-push for PppPackage (Profil PPP), reusing
 * PushHotspotPackageToMikrotikJob's exact async/retry/backoff shape
 * (v0.14.4) rather than reinventing it.
 *
 * Pushes a BRAND-NEW, SEPARATE `/ppp profile` object — NOT the same object
 * its parent Grup Profil already pushes since v0.14.3. Confirmed
 * architecture (per the sprint brief, matching the already-established
 * "Grup Profil's own bare /ppp profile is the PPPoE Server's Default
 * Profile" finding — see CLAUDE.md's "Revisi Grup Profil" section):
 * local-address/dns-server/parent-queue are INHERITED from the parent Grup
 * Profil and resolved LIVE on every single push (never copied/cached onto
 * PppPackage itself) — same "resolve the live current value, don't snapshot
 * it" discipline HotspotPackage already established for its own
 * address-pool inheritance. rate-limit comes from this package's own
 * BandwidthProfile; session-timeout from this package's own Masa Aktif.
 *
 * Lookup is by PppPackage::mikrotikComment() (a stable comment, like
 * NetworkProfileGroup's own PPP push) — NOT the mikrotikLookupName()/
 * mikrotik_profile_name workaround HotspotPackage needed, since `/ppp
 * profile` genuinely supports `comment` (confirmed via a live test, see
 * RouterOsGateway::syncPppProfile()'s own docblock).
 *
 * Aturan nama final (2026-09-05) — nama Profil PPP BOLEH sama dengan Grup
 * Profil ppp / Profil PPP lain (dunia PPP bebas). Yang dikirim ke router
 * bukan $package->name verbatim tapi PppPackage::routerOsProfileName(),
 * yang otomatis menambah suffix " (pkg #{id})" HANYA saat bentrok — supaya
 * `/ppp profile` (namespace unik router-wide) tidak ditolak RouterOS.
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

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null || $package->networkProfileGroup->customerIpPool === null || $package->bandwidthProfile === null) {
            Log::warning("PushPppPackageToMikrotikJob: PppPackage #{$this->pppPackageId}, Grup Profil, NAS, IP Pool, atau Bandwidth Profile terkait tidak ditemukan, dilewati.");

            return;
        }

        $group = $package->networkProfileGroup;
        $nas = $group->nas;
        $bandwidth = $package->bandwidthProfile;

        // Same dns-server combination logic as PushNetworkProfileGroupToMikrotikJob's
        // own syncPpp() — reused, not reinvented. Inherited from the parent
        // Grup Profil, resolved fresh on every push.
        $dnsServers = array_values(array_filter([$group->dns_primary, $group->dns_secondary]));
        $dnsServer = $dnsServers === [] ? null : implode(',', $dnsServers);

        // Revisi Prioritas Dropdown — `/ppp profile` has no standalone
        // `priority` parameter (confirmed live, "unknown parameter
        // priority"); pushed via the extended rate-limit syntax's 5th
        // slot instead — see App\Support\RouterOsQueuePriority's own
        // docblock for the full live-verified reasoning.
        $rateLimit = RouterOsQueuePriority::toRateLimitString($bandwidth->upload_max, $bandwidth->download_max, $package->priority);

        $result = $gateway->syncPppProfile(
            $nas,
            $package->mikrotikComment(),
            // Aturan nama final (FIX 2) — nama yang dikirim ke router BUKAN
            // selalu $package->name verbatim: kalau bentrok dengan Grup
            // Profil ppp / Profil PPP lain di NAS yang sama, pakai suffix
            // " (pkg #{id})". Nama tampilan di BOSS App tidak berubah.
            $package->routerOsProfileName(),
            $group->customerIpPool->name,
            $dnsServer,
            $group->parent_queue,
            null,
            $rateLimit,
            $package->routerOsSessionTimeout(),
        );

        if ($result['success']) {
            $package->markSynced();

            return;
        }

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
