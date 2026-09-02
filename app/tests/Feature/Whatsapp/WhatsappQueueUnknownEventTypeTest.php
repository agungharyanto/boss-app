<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Livewire\Whatsapp\WhatsappGatewayIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regresi: sebuah baris `whatsapp_message_logs` dengan `event_type` yang
 * TIDAK dikenal enum yang sedang berjalan (case dihapus/di-rename, atau
 * di-seed branch fitur yang lebih baru & belum di-merge lalu working tree
 * balik ke branch lama) TIDAK BOLEH meng-500-kan halaman/endpoint antrian —
 * cukup disembunyikan (`WhatsappMessageLog::scopeKnownEventType()`).
 */
class WhatsappQueueUnknownEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Tenant $tenant): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function insertUnknownEventLog(Tenant $tenant): int
    {
        // Bypass the model (its cast would reject the value) — raw insert.
        return DB::table('whatsapp_message_logs')->insertGetId([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'phone_number' => '081200000000',
            'event_type' => 'a_removed_or_future_event_type',
            'rendered_content' => 'x',
            'status' => 'failed',
            'attempts' => 1,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_scope_excludes_unknown_event_type_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $this->insertUnknownEventLog($tenant);
        $known = WhatsappMessageLog::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        $ids = WhatsappMessageLog::withoutGlobalScopes()->knownEventType()->pluck('id');

        $this->assertTrue($ids->contains($known->id));
        $this->assertCount(1, $ids);
    }

    public function test_livewire_antrian_tab_does_not_500_on_an_unknown_event_type_row(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $this->insertUnknownEventLog($tenant);
        WhatsappMessageLog::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
            'phone_number' => '081277777777',
        ]);

        Livewire::actingAs($admin)
            ->test(WhatsappGatewayIndex::class)
            ->set('tab', 'antrian')
            ->assertOk()
            ->assertSee('081277777777')
            ->assertDontSee('081200000000');
    }

    public function test_api_message_logs_index_does_not_500_on_an_unknown_event_type_row(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->admin($tenant);
        $this->insertUnknownEventLog($tenant);
        WhatsappMessageLog::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/whatsapp/message-logs');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
