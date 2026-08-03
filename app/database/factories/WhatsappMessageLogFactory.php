<?php

namespace Database\Factories;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Models\Customer;
use App\Models\WhatsappMessageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessageLog>
 */
class WhatsappMessageLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // tenant_id/reseller_id always derived from the customer, never an
        // independent random tenant — same convention as
        // CustomerContactFactory (see CLAUDE.md).
        $customer = Customer::factory()->create();

        return [
            'tenant_id' => $customer->tenant_id,
            'reseller_id' => $customer->reseller_id,
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'phone_number' => $customer->phone_number,
            'event_type' => WhatsappEventType::InvoiceDueReminder,
            'template_id' => null,
            'rendered_content' => 'Halo '.$customer->name.', ini pesan uji coba.',
            'status' => WhatsappMessageStatus::Queued,
            'failed_reason' => null,
            'attempts' => 0,
            'queued_at' => now(),
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => WhatsappMessageStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WhatsappMessageStatus::Failed,
            'failed_reason' => 'Simulated failure',
            'attempts' => 3,
        ]);
    }
}
