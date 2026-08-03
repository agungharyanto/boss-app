<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\CustomerStatus;
use App\Enums\WhatsappEventType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendWhatsappSuspendedRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function suspendedCustomer(): Customer
    {
        $tenant = Tenant::factory()->create();

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::CustomerSuspendedReminder,
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

        $this->travelTo(now()->startOfDay());
        $this->artisan('whatsapp:send-suspended-reminders')->assertSuccessful();

        $this->travelTo(now()->addDay());
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
