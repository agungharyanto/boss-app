<?php

namespace App\Listeners;

use App\Events\OdpCapacityExhausted;
use App\Models\User;
use App\Notifications\OdpCapacityExhaustedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Queued
 * (redis, same as every other async side effect in this codebase) so
 * dispatching the event never adds latency to the real caller
 * (registration/work-order flow) — auto-discovered by Laravel's own
 * listener discovery (app/Listeners + a handle(EventClass $event) method
 * signature), no manual registration needed.
 */
class NotifyOdpCapacityExhausted implements ShouldQueue
{
    public function handle(OdpCapacityExhausted $event): void
    {
        $recipients = User::where('tenant_id', $event->customer->tenant_id)
            ->get()
            ->filter(fn (User $user) => $user->can('network_infrastructure.manage'));

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new OdpCapacityExhaustedNotification($event->customer));
    }
}
