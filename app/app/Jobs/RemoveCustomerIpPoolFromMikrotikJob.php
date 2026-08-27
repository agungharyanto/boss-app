<?php

namespace App\Jobs;

use App\Models\CustomerIpPool;
use App\Services\Network\Contracts\RouterOsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v0.14.2.1 — companion to PushCustomerIpPoolToMikrotikJob, dispatched by
 * CustomerIpPoolService::delete() AFTER the row is already soft-deleted in
 * boss_db. Soft-delete doesn't clear nas_id/name/range_start/range_end or
 * what mikrotikComment() derives from them — withTrashed() below is what
 * makes those still readable here.
 */
class RemoveCustomerIpPoolFromMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $customerIpPoolId) {}

    public function handle(RouterOsGateway $gateway): void
    {
        $pool = CustomerIpPool::withoutGlobalScopes()->withTrashed()->with('nas')->find($this->customerIpPoolId);

        if ($pool === null || $pool->nas === null) {
            Log::warning("RemoveCustomerIpPoolFromMikrotikJob: CustomerIpPool #{$this->customerIpPoolId} atau NAS terkait tidak ditemukan, dilewati.");

            return;
        }

        $result = $gateway->removeIpPool($pool->nas, $pool->mikrotikComment());

        if ($result['success']) {
            return;
        }

        $this->recordFailure($pool, $result['message'] ?? 'Unknown failure');
    }

    public function failed(?Throwable $exception): void
    {
        $pool = CustomerIpPool::withoutGlobalScopes()->withTrashed()->find($this->customerIpPoolId);

        // The row is already soft-deleted/gone from the UI at this point —
        // recorded for audit visibility (findable via tinker) only, no
        // dedicated trash-bin UI exists to surface this in this sprint.
        $pool?->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.($exception?->getMessage() ?? 'Unknown failure')]);
    }

    private function recordFailure(CustomerIpPool $pool, string $reason): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $pool->update(['mikrotik_sync_error' => 'Gagal hapus dari router: '.$reason]);

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
