<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappGatewayService
{
    /**
     * The only channel with a real "shareable link" concept among the three
     * Xendit categories this codebase integrates (v0.3.5) — VA/QRIS numbers
     * aren't a URL a customer can tap from a WhatsApp message.
     */
    private const PAYMENT_LINK_CHANNEL_CODE = 'XENDIT_INVOICE';

    public function __construct(
        private readonly WhatsappTemplateService $templateService,
    ) {}

    /**
     * Resolve template + render + log + dispatch, for any of the four
     * event_types this module fires. Returns null (no exception) when no
     * template resolves at all — a seeding gap, not something that should
     * ever fail the caller's own transaction (invoice markPaid, customer
     * registration, etc).
     */
    public function buildAndQueue(WhatsappEventType $eventType, Customer $customer, ?Invoice $invoice = null): ?WhatsappMessageLog
    {
        $template = $this->templateService->resolve($eventType, $customer->tenant_id, $customer->reseller_id);

        if ($template === null) {
            Log::warning("WhatsappGatewayService: no active template resolved for {$eventType->value} (tenant_id={$customer->tenant_id}, reseller_id={$customer->reseller_id}) — skipping send.");

            return null;
        }

        $variables = $this->buildVariables($eventType, $customer, $invoice);
        $rendered = $this->templateService->render($template->content, $variables);

        $log = WhatsappMessageLog::create([
            'tenant_id' => $customer->tenant_id,
            'reseller_id' => $customer->reseller_id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'phone_number' => $customer->phone_number,
            'event_type' => $eventType,
            'template_id' => $template->id,
            'rendered_content' => $rendered,
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ]);

        $sessionKey = WhatsappSession::sessionKeyFor($customer->reseller_id);

        SendWhatsappMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);

        return $log;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariables(WhatsappEventType $eventType, Customer $customer, ?Invoice $invoice): array
    {
        $reseller = $customer->reseller_id !== null ? $customer->reseller : null;
        $companyName = $reseller?->name ?? $customer->tenant?->name;

        $variables = [
            'customer_name' => $customer->name,
            'customer_id' => $customer->id,
            'invoice_number' => $invoice?->invoice_number,
            'due_date' => $invoice?->due_date?->format('d/m/Y'),
            'total_amount' => $invoice !== null ? 'Rp'.number_format((float) $invoice->grand_total, 0, ',', '.') : null,
            'package_name' => $invoice?->subscription?->name,
            'company_name' => $companyName,
            'payment_link' => null,
        ];

        // Confirmed decision (v0.4.0): generate a fresh Xendit Invoice link
        // on demand at reminder time — no invoice_url is persisted anywhere
        // ahead of time (Payment rows are only ever created when a payment
        // attempt actually starts).
        if ($eventType === WhatsappEventType::InvoiceDueReminder && $invoice !== null) {
            $variables['payment_link'] = $this->resolvePaymentLink($invoice);
        }

        return $variables;
    }

    /**
     * Manual "Retry" button in the Antrian tab — only meaningful for a
     * message that actually failed; resets it to queued and re-dispatches,
     * with attempts reset so it gets the job's full 3-try budget again.
     */
    public function retry(WhatsappMessageLog $log): void
    {
        if ($log->status !== WhatsappMessageStatus::Failed) {
            return;
        }

        $log->update([
            'status' => WhatsappMessageStatus::Queued,
            'attempts' => 0,
            'failed_reason' => null,
        ]);

        $sessionKey = WhatsappSession::sessionKeyFor($log->reseller_id);

        SendWhatsappMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);
    }

    private function resolvePaymentLink(Invoice $invoice): ?string
    {
        try {
            // Resolved lazily (not constructor-injected) — PaymentService
            // depends on InvoiceService, which depends on this class; an
            // eager constructor dependency here would be a circular
            // resolution loop (InvoiceService -> WhatsappGatewayService ->
            // PaymentService -> InvoiceService -> ...) that exhausts memory
            // building the container graph before any code even runs.
            $payment = app(PaymentService::class)->createPaymentFor($invoice, self::PAYMENT_LINK_CHANNEL_CODE);

            return $payment->raw_response['invoice_url'] ?? null;
        } catch (Throwable $e) {
            // Missing/disabled XENDIT_INVOICE channel, invoice already
            // paid/cancelled by a race, Xendit API error, etc. — the
            // reminder must still go out without a link rather than never
            // send at all.
            Log::warning("WhatsappGatewayService: failed to generate payment_link for invoice {$invoice->invoice_number}: {$e->getMessage()}");

            return null;
        }
    }
}
