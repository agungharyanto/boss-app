<?php

namespace Tests\Feature\Network;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * cpe:auto-match-legacy-devices is deliberately NOT registered via
 * Schedule::command() — its interval is configurable below Laravel's
 * 1-minute scheduler granularity (CPE_AUTO_MATCH_INTERVAL_SECONDS, root
 * .env), which ScheduleRunCommand::repeatEvents() can't serve reliably
 * under boss-scheduler's own non-cron "while true; schedule:run; sleep 60"
 * invocation pattern (see routes/console.php's own comment for the full
 * reasoning, confirmed by reading ScheduleRunCommand's source directly).
 * It's run instead by a second, independent while-loop in boss-scheduler's
 * entrypoint (docker-compose.yml). This test is a regression guard against
 * accidentally re-adding a Schedule::command() registration for it later
 * (which would double-run it — once via that loop, once via schedule:run).
 */
class AutoMatchLegacyDevicesScheduleTest extends TestCase
{
    public function test_command_is_not_registered_in_laravels_own_schedule(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command, 'cpe:auto-match-legacy-devices'));

        $this->assertNull(
            $event,
            'cpe:auto-match-legacy-devices should NOT be registered via Schedule::command() — '
            .'it runs from its own dedicated loop in boss-scheduler\'s entrypoint instead '
            .'(see docker-compose.yml + routes/console.php for why).'
        );
    }
}
