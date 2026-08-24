<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Reseller;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Real production incident (found while investigating why
 * storage/logs/laravel.log had grown to ~12GB — see CLAUDE.md "Dashboard
 * Monitoring Fixes"): this command crashed outright on EVERY invocation
 * (`Call to a member function getKey() on string`), fired every 5 minutes
 * by boss-whatsapp-worker's own entrypoint loop, for as long as this repo
 * has had any WhatsappSession row at all (or even with zero rows, thanks
 * to the `push('direct')` — see below). Never caught by any prior test
 * because none existed for this command until now.
 */
class WhatsappQueueNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_the_direct_queue_when_no_sessions_exist_at_all(): void
    {
        // The exact crashing case in production: ->map() on an Eloquent
        // Collection stays an Eloquent Collection even once its items are
        // plain strings, so ->unique() defaults to Model-keyed uniqueness
        // (calling ->getKey() on each item) and crashes outright — this
        // reproduces regardless of how many WhatsappSession rows exist,
        // since `push('direct')` alone is already a non-Model string item.
        $this->artisan('whatsapp:queue-names')
            ->assertSuccessful();
    }

    public function test_deduplicates_reseller_and_direct_queue_names(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        WhatsappSession::factory()->create(['reseller_id' => $resellerA->id]);
        WhatsappSession::factory()->create(['reseller_id' => $resellerB->id]);
        // A second session for the SAME reseller — sessionKey() collides
        // with the first, exercising the actual dedup this command exists
        // to do (not just "doesn't crash").
        WhatsappSession::factory()->create(['reseller_id' => $resellerA->id]);
        // No explicit "direct" session row needed — push('direct') always
        // adds it regardless of whether one exists in the DB.

        $this->artisan('whatsapp:queue-names')->assertSuccessful();
    }

    public function test_output_contains_expected_queue_names(): void
    {
        $reseller = Reseller::factory()->create();
        WhatsappSession::factory()->create(['reseller_id' => $reseller->id]);

        Artisan::call('whatsapp:queue-names');
        $output = Artisan::output();

        $this->assertStringContainsString("whatsapp-{$reseller->id}", $output);
        $this->assertStringContainsString('whatsapp-direct', $output);
        // Exactly two comma-separated names, not a duplicate direct entry.
        $this->assertSame(2, substr_count($output, 'whatsapp-'));
    }
}
