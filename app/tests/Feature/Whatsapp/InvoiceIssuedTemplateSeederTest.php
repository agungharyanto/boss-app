<?php

namespace Tests\Feature\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use Database\Seeders\WhatsappMessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.13.5.3 — seeder menambahkan template default invoice_issued; idempoten
 * (firstOrCreate) & tidak menimpa yang sudah diedit admin.
 */
class InvoiceIssuedTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_invoice_issued_template_for_each_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $this->seed(WhatsappMessageTemplateSeeder::class);

        $template = WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('reseller_id')
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->first();

        $this->assertNotNull($template);
        $this->assertTrue($template->is_active);
        $this->assertStringContainsString('{customer_name}', $template->content);
        $this->assertStringContainsString('{cs_number}', $template->content);
        $this->assertStringNotContainsString('{payment_link}', $template->content);
    }

    public function test_is_idempotent_and_does_not_overwrite_admin_edit(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seed(WhatsappMessageTemplateSeeder::class);

        $template = WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->first();
        $template->update(['content' => 'ISI HASIL EDIT ADMIN', 'is_active' => false]);

        // Jalankan ulang — tidak boleh menimpa.
        $this->seed(WhatsappMessageTemplateSeeder::class);

        $template->refresh();
        $this->assertSame('ISI HASIL EDIT ADMIN', $template->content);
        $this->assertFalse($template->is_active);
        $this->assertSame(
            1,
            WhatsappMessageTemplate::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereNull('reseller_id')
                ->where('event_type', WhatsappEventType::InvoiceIssued->value)
                ->count()
        );
    }
}
