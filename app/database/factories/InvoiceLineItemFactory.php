<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLineItem>
 */
class InvoiceLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(2, 100000, 500000);

        return [
            'invoice_id' => Invoice::factory(),
            // Must match the parent invoice's tenant, not an independent random tenant.
            'tenant_id' => fn (array $attributes) => Invoice::withoutGlobalScopes()->find($attributes['invoice_id'])?->tenant_id,
            'description' => $this->faker->words(3, true),
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice,
        ];
    }
}
