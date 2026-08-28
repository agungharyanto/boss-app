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
 * Revisi Grup Profil (Langkah 3) — companion to PushExpiredProfileToMikrotikJob,
 * dispatched by NasService::updateExpiredIpPool() when an admin CLEARS a
 * NAS's expired_ip_pool_id (sets it back to null) after it had previously
 * been synced. The `/ppp profile` object this NAS pushed is fully
 * BOSS-App-owned (unlike Grup Profil's Hotspot-type /ip hotspot SERVER,
 * which BOSS App never owns the lifecycle of) — safe to actually remove,
 * same reasoning as RemoveNetworkProfileGroupFromMikrotikJob's Ppp branch.
 *
 * Takes the comment/name directly as constructor args (not re-derived from
 * a fresh Nas::find()) — by the time this runs, expired_ip_pool_id is
 * already null on the real row, so there is nothing left to look up; the
 * comment string is stable and NAS-id-derived, computable without a fresh
 * model fetch.
 */
class RemoveExpiredProfileFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $nasId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $nas = Nas::find($this->nasId);

        if ($nas === null) {
            Log::warning("RemoveExpiredProfileFromMikrotikJob: NAS #{$this->nasId} tidak ditemukan, dilewati.");

            return;
        }

        $result = $gateway->removePppProfile($nas, $nas->expiredProfileMikrotikComment());

        if ($result['success']) {
            return;
        }

        $this->recordFailure($nas, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $nas = Nas::find($this->nasId);

        $nas?->update(['expired_profile_mikrotik_sync_error' => 'Gagal hapus dari router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(Nas $nas, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $nas->update(['expired_profile_mikrotik_sync_error' => 'Gagal hapus dari router: '.$reason]);

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
