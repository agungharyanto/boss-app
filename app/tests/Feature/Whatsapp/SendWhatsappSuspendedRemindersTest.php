<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\CustomerStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendWhatsappSuspendedRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // v0.28.2 — gerbang menuntut dalam jendela jam (09:00 WIB).
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function suspendedCustomer(): Customer
    {
        $tenant = Tenant::factory()->create();

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::CustomerSuspendedReminder,
        ]);

        // v0.28.2 — gerbang menuntut sesi tujuan Connected.
        WhatsappSession::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'status' => WhatsappSessionStatus::Connected,
        ]);

        return Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'status' => CustomerStatus::Suspend,
        ]);
    }

    public function test_suspended_customer_receives_a_reminder(): void
    {
        Bus::fake();

        $customer = $this->suspendedCustomer();

        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'customer_id' => $customer->id,
            'event_type' => WhatsappEventType::CustomerSuspendedReminder->value,
        ]);
        Bus::assertDispatched(SendWhatsappMessageJob::class);
    }

    public function test_running_the_command_twice_the_same_day_does_not_duplicate_the_reminder(): void
    {
        Bus::fake();

        $customer = $this->suspendedCustomer();

        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();
        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->assertSame(
            1,
            WhatsappMessageLog::where('customer_id', $customer->id)
                ->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)
                ->count()
        );
    }

    public function test_still_suspended_the_next_day_receives_another_reminder(): void
    {
        Bus::fake();

        $customer = $this->suspendedCustomer();

        // v0.28.2 — waktu WIB eksplisit DALAM jendela (startOfDay=00:00 kini di
        // luar jendela gerbang). Hari 1 lalu hari 2, keduanya 09:00 WIB.
        $this->travelTo(Carbon::create(2026, 11, 21, 9, 0, 0, 'Asia/Jakarta'));
        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->travelTo(Carbon::create(2026, 11, 22, 9, 0, 0, 'Asia/Jakarta'));
        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->assertSame(
            2,
            WhatsappMessageLog::where('customer_id', $customer->id)
                ->where('event_type', WhatsappEventType::CustomerSuspendedReminder->value)
                ->count()
        );

        $this->travelBack();
    }

    public function test_stops_automatically_once_status_changes_away_from_suspend(): void
    {
        Bus::fake();

        $customer = $this->suspendedCustomer();
        $customer->update(['status' => CustomerStatus::Aktif]);

        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->assertDatabaseMissing('whatsapp_message_logs', [
            'customer_id' => $customer->id,
            'event_type' => WhatsappEventType::CustomerSuspendedReminder->value,
        ]);
    }
}
