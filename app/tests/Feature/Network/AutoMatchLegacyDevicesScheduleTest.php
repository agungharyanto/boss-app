<?php

namespace Tests\Feature\Network;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class AutoMatchLegacyDevicesScheduleTest extends TestCase
{
    public function test_command_is_scheduled_every_fifteen_minutes(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command, 'cpe:auto-match-legacy-devices'));

        $this->assertNotNull($event, 'cpe:auto-match-legacy-devices is not registered in the schedule.');
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
