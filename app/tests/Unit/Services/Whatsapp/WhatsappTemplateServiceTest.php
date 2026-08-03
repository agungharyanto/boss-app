<?php

namespace Tests\Unit\Services\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\Reseller;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use App\Services\Whatsapp\WhatsappTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_override_is_used_when_active(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'default content',
        ]);
        $override = WhatsappMessageTemplate::factory()->forReseller($reseller)->create([
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'override content',
        ]);

        $resolved = (new WhatsappTemplateService)->resolve(WhatsappEventType::PaymentReceived, $tenant->id, $reseller->id);

        $this->assertSame($override->id, $resolved->id);
        $this->assertSame('override content', $resolved->content);
    }

    public function test_falls_back_to_default_when_reseller_override_is_inactive(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $default = WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'default content',
        ]);
        WhatsappMessageTemplate::factory()->forReseller($reseller)->inactive()->create([
            'event_type' => WhatsappEventType::PaymentReceived,
            'content' => 'inactive override',
        ]);

        $resolved = (new WhatsappTemplateService)->resolve(WhatsappEventType::PaymentReceived, $tenant->id, $reseller->id);

        $this->assertSame($default->id, $resolved->id);
    }

    public function test_falls_back_to_default_when_no_override_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $reseller = Reseller::factory()->create(['tenant_id' => $tenant->id]);

        $default = WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::PaymentReceived,
        ]);

        $resolved = (new WhatsappTemplateService)->resolve(WhatsappEventType::PaymentReceived, $tenant->id, $reseller->id);

        $this->assertSame($default->id, $resolved->id);
    }

    public function test_returns_null_when_neither_override_nor_default_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $resolved = (new WhatsappTemplateService)->resolve(WhatsappEventType::PaymentReceived, $tenant->id, null);

        $this->assertNull($resolved);
    }

    public function test_render_replaces_variables_with_values(): void
    {
        $rendered = (new WhatsappTemplateService)->render(
            'Halo {customer_name}, tagihan {invoice_number} sebesar {total_amount}.',
            ['customer_name' => 'Budi', 'invoice_number' => 'INV-001', 'total_amount' => 'Rp100.000']
        );

        $this->assertSame('Halo Budi, tagihan INV-001 sebesar Rp100.000.', $rendered);
    }

    public function test_render_replaces_null_variables_with_empty_string(): void
    {
        $rendered = (new WhatsappTemplateService)->render(
            'Link pembayaran: {payment_link}.',
            ['payment_link' => null]
        );

        $this->assertSame('Link pembayaran: .', $rendered);
    }

    public function test_render_strips_placeholders_with_no_supplied_variable_at_all(): void
    {
        $rendered = (new WhatsappTemplateService)->render(
            'Halo {customer_name}, {unknown_variable} disini.',
            ['customer_name' => 'Budi']
        );

        $this->assertSame('Halo Budi,  disini.', $rendered);
    }
}
