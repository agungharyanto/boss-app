<?php

namespace Database\Factories;

use App\Enums\WhatsappEventType;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessageTemplate>
 */
class WhatsappMessageTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceDueReminder,
            'content' => 'Halo {customer_name}, invoice {invoice_number} sebesar {total_amount} jatuh tempo {due_date}.',
            'is_active' => true,
            'updated_by' => null,
        ];
    }

    /**
     * A reseller-owned override. tenant_id always derived from the given
     * reseller's own tenant_id, same convention as WhatsappSessionFactory.
     */
    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => [
            'reseller_id' => $reseller->id,
            'tenant_id' => $reseller->tenant_id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
