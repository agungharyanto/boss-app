<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappResellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function resellerOwner(Tenant $tenant, Reseller $reseller): User
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $reseller->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return $owner;
    }

    public function test_api_index_only_returns_the_acting_resellers_own_session(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $sessionA = WhatsappSession::factory()->forReseller($resellerA)->create();
        WhatsappSession::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/whatsapp/sessions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sessionA->id));
        $this->assertCount(1, $ids);
    }

    public function test_api_show_404s_for_another_resellers_session_via_route_model_binding(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappSession::factory()->forReseller($resellerA)->create();
        $sessionB = WhatsappSession::factory()->forReseller($resellerB)->create();

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->getJson("/api/v1/whatsapp/sessions/{$sessionB->id}")
            ->assertNotFound();
    }

    public function test_api_message_logs_index_only_returns_the_acting_resellers_own_queue(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $logA = WhatsappMessageLog::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerA->id]);
        WhatsappMessageLog::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/whatsapp/message-logs');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($logA->id));
        $this->assertCount(1, $ids);
    }

    public function test_reseller_cannot_retry_another_resellers_message_log(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $logB = WhatsappMessageLog::factory()->failed()->create(['tenant_id' => $tenant->id, 'reseller_id' => $resellerB->id]);

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        $this->actingAs($ownerA)
            ->postJson("/api/v1/whatsapp/message-logs/{$logB->id}/retry")
            ->assertNotFound();
    }

    public function test_reseller_cannot_update_another_resellers_template_override(): void
    {
        $tenant = Tenant::factory()->create();
        $resellerA = Reseller::factory()->create(['tenant_id' => $tenant->id]);
        $resellerB = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => 'payment_received',
        ]);
        $overrideB = WhatsappMessageTemplate::factory()->forReseller($resellerB)->create(['event_type' => 'payment_received']);

        $ownerA = $this->resellerOwner($tenant, $resellerA);

        // The controller resolves reseller_id from the ACTING user's own
        // ResellerContext, never from a route param — so this is A trying
        // to write under A's own context, but asserting it lands nowhere
        // near B's row rather than 404ing (there's no {template} route
        // param to bind against here at all).
        $this->actingAs($ownerA)
            ->putJson('/api/v1/whatsapp/templates/payment_received', ['content' => 'hijacked content'])
            ->assertOk();

        $this->assertDatabaseMissing('whatsapp_message_templates', [
            'id' => $overrideB->id,
            'content' => 'hijacked content',
        ]);
    }
}
