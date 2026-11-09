<?php

namespace Tests\Feature\Whatsapp;

use App\Exceptions\WhatsappAttachmentException;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Whatsapp\WhatsappAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.13.5.3 — WhatsappAttachment::fromInvoicePdf: prefix invoice-pdfs/, MIME
 * pdf, kepemilikan tenant lewat Invoice induk (tenant lain ditolak).
 */
class WhatsappAttachmentInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(int $tenantId): Invoice
    {
        $subscription = Subscription::factory()->create(['tenant_id' => $tenantId]);

        return Invoice::factory()->create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
        ]);
    }

    public function test_builds_pdf_attachment_with_correct_path_and_mime(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->invoice($tenant->id);

        $attachment = WhatsappAttachment::fromInvoicePdf($invoice, $tenant->id);

        $this->assertSame('local', $attachment->disk);
        $this->assertSame("invoice-pdfs/{$tenant->id}/{$invoice->id}.pdf", $attachment->path);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringStartsWith('invoice-pdfs/', $attachment->path);
    }

    public function test_rejects_tenant_mismatch(): void
    {
        $tenant = Tenant::factory()->create();
        $invoice = $this->invoice($tenant->id);

        $this->expectException(WhatsappAttachmentException::class);
        WhatsappAttachment::fromInvoicePdf($invoice, $tenant->id + 999);
    }
}
