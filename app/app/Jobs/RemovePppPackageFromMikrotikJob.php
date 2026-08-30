<?php

namespace App\Jobs;

use App\Models\PppPackage;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.5 — companion to PushPppPackageToMikrotikJob, dispatched by
 * PppPackageService::delete(). A PppPackage's own `/ppp profile` object is
 * fully BOSS-App-created and owned (a genuinely separate object from its
 * parent Grup Profil's own `/ppp profile`, never shared) — safe, and
 * correct, to actually remove it, same posture as
 * RemoveHotspotPackageFromMikrotikJob.
 */
class RemovePppPackageFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $pppPackageId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()->with('networkProfileGroup.nas')->find($this->pppPackageId);

        if ($package === null || $package->networkProfileGroup === null || $package->networkProfileGroup->nas === null) {
            Log::warning("RemovePppPackageFromMikrotikJob: PppPackage #{$this->pppPackageId}, Grup Profil, atau NAS terkait tidak ditemukan, dilewati.");

            return;
        }

        $result = $gateway->removePppProfile($package->networkProfileGroup->nas, $package->mikrotikComment());

        if ($result['success']) {
            return;
        }

        $this->recordFailure($package, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $package = PppPackage::withoutGlobalScopes()->withTrashed()->find($this->pppPackageId);

        $package?->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(PppPackage $package, string $reason): void
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
