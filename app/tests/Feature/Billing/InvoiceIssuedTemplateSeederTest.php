<?php

namespace Tests\Feature\Billing;

use App\Enums\WhatsappEventType;
use App\Models\Tenant;
use App\Models\WhatsappMessageTemplate;
use Database\Seeders\WhatsappMessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.13.5.4 — seeder invoice_issued + {payment_instructions}.
 */
class InvoiceIssuedTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'Halo {customer_name}, tagihan internet Anda sudah terbit.'."\n".'Invoice: {invoice_number}'."\n".'Paket: {package_name}'."\n".'Total: {total_amount}'."\n".'Jatuh tempo: {due_date}'."\n".'Rincian lengkap ada pada file PDF terlampir.'."\n".'Bantuan: CS {cs_number}'."\n".'Terima kasih - {company_name}';

    private function tpl(int $tenantId): WhatsappMessageTemplate
    {
        return WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('reseller_id')
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->firstOrFail();
    }

    public function test_new_tenant_gets_payment_instructions_variable(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seed(WhatsappMessageTemplateSeeder::class);

        $this->assertStringContainsString('{payment_instructions}', $this->tpl($tenant->id)->content);
    }

    public function test_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seed(WhatsappMessageTemplateSeeder::class);
        $this->seed(WhatsappMessageTemplateSeeder::class);

        $this->assertEquals(1, WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->whereNull('reseller_id')
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)->count());
    }

    public function test_legacy_template_is_upgraded(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappMessageTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'content' => self::LEGACY,
            'is_active' => true,
        ]);

        $this->seed(WhatsappMessageTemplateSeeder::class);

        $this->assertStringContainsString('{payment_instructions}', $this->tpl($tenant->id)->content);
    }

    public function test_edited_template_is_left_untouched(): void
    {
        $tenant = Tenant::factory()->create();
        $edited = 'Tagihan Anda terbit, bayar ya kak — {company_name}';
        WhatsappMessageTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::InvoiceIssued->value,
            'content' => $edited,
            'is_active' => true,
        ]);

        $this->seed(WhatsappMessageTemplateSeeder::class);

        $this->assertSame($edited, $this->tpl($tenant->id)->content);
    }
}
