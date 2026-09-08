<?php

namespace App\Jobs;

use App\Models\RemoteWanConfig;
use App\Services\Network\GenieAcsPresetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Push baris singleton `RemoteWanConfig` ke GenieACS (provision `default-wan`
 * + `args` preset `default`). Async — dispatch oleh
 * `RemoteWanConfigService::save()` SETELAH baris di-commit, tidak pernah
 * memblokir submit form pada keterjangkauan genieacs-nbi.
 *
 * Pola retry/backoff identik `PushCustomerIpPoolToMikrotikJob`
 * (30s / 2min / 5min, `tries = 3`).
 */
class SyncRemoteWanConfigToGenieAcsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(GenieAcsPresetService $presets): void
    {
        $config = RemoteWanConfig::current();

        try {
            $presets->syncAutoWanConfig($config);
        } catch (Throwable $e) {
            $this->recordFailure($config, $e->getMessage());

            return;
        }

        $config->markSynced();
    }

    public function failed(?Throwable $exception): void
    {
        RemoteWanConfig::current()->markSyncFailed($exception?->getMessage() ?? 'Unknown failure');
    }

    private function recordFailure(RemoteWanConfig $config, string $reason): void
    {
        if ($this->attempts() >= $this->tries) {
            $config->markSyncFailed($reason);

            return;
        }

        $config->forceFill(['genieacs_sync_error' => $reason])->save();

        $this->release(match ($this->attempts()) {
            1 => 30,
            2 => 120,
            default => 300,
        });
    }
}
