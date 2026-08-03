<?php

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGatewayChannelCategory;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingResult;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGatewayChannel;
use App\Models\PaymentWebhookLog;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(
        private readonly XenditGatewayService $gateway,
        private readonly InvoiceService $invoiceService,
        private readonly PaymentGatewaySettingsService $gatewaySettings,
    ) {}

    /**
     * Creates a payment channel for $invoice via Xendit, using
     * invoice_number — NOT the numeric id — as Xendit's external_id (RULE
     * from v0.3.4 handoff, see CLAUDE.md). $channelCode is a
     * payment_gateway_channels.code (v0.3.5 Fase H — dynamic admin-managed
     * catalog, not a fixed 3-case enum anymore).
     */
    public function createPaymentFor(Invoice $invoice, string $channelCode): Payment
    {
        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            throw new InvalidArgumentException("Invoice {$invoice->invoice_number} sudah {$invoice->status->label()} — tidak bisa membuat payment baru.");
        }

        $channel = PaymentGatewayChannel::query()->where('code', $channelCode)->first();

        if ($channel === null || ! $channel->enabled) {
            throw new InvalidArgumentException("Channel pembayaran '{$channelCode}' tidak aktif atau tidak dikenal — aktifkan dulu di Pengaturan > Payment Gateway.");
        }

        $amount = (float) $invoice->grand_total;

        $response = match ($channel->category) {
            // Only these three categories have a real Xendit integration
            // this sprint (VA+QRIS+Invoice was the original v0.3.5 scope
            // decision) — ewallet/retail_outlet/credit_card exist in the
            // catalog/checklist UI but aren't wired to a gateway call yet,
            // see PaymentGatewayChannelSeeder's docblock.
            PaymentGatewayChannelCategory::BankTransferVa => $this->gateway->createVirtualAccount(
                $invoice->invoice_number, $amount, Str::before($channel->code, '_VA')
            ),
            PaymentGatewayChannelCategory::Qris => $this->gateway->createQris($invoice->invoice_number, $amount),
            PaymentGatewayChannelCategory::Invoice => $this->gateway->createInvoice($invoice->invoice_number, $amount),
            default => throw new InvalidArgumentException(
                "Channel kategori '{$channel->category->label()}' belum didukung untuk membuat payment — baru terdaftar di katalog, integrasi API Xendit-nya menyusul sprint lain."
            ),
        };

        return Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'xendit_reference_id' => $response['id'] ?? null,
            'channel_type' => $channel->code,
            'amount' => $amount,
            'status' => isset($response['id']) ? PaymentStatus::Pending : PaymentStatus::Failed,
            'raw_response' => $response,
        ]);
    }

    /**
     * Entry point for the Xendit webhook controller.
     *
     * Order matters and mirrors the confirmed spec exactly:
     * 1. Verify signature FIRST — payload contents are not inspected at all
     *    before this passes (x-callback-token compared with hash_equals,
     *    not HMAC — that's genuinely how Xendit's callback verification
     *    works, a shared static token, not a computed signature).
     * 2. THEN, inside a DB transaction, check idempotency
     *    (xendit_event_id already logged) with a row lock.
     * 3. Exact amount match against invoices.grand_total.
     * 4. Only on full success: InvoiceService::markPaid() — the ONLY
     *    place in this codebase allowed to move an invoice to paid.
     *
     * Always returns a WebhookProcessingResult, never throws for a "normal"
     * rejection — the controller responds HTTP 200 regardless (a non-200
     * makes Xendit retry the same webhook indefinitely).
     */
    public function handleWebhook(array $payload, ?string $signatureHeader): WebhookProcessingResult
    {
        $eventId = $this->resolveEventId($payload);

        if (! $this->verifySignature($signatureHeader)) {
            // firstOrCreate (not create) — a replayed invalid-signature
            // webhook must not crash on the xendit_event_id unique
            // constraint; wasRecentlyCreated tells "genuinely new" from
            // "already logged" apart without a separate query.
            $log = PaymentWebhookLog::firstOrCreate(
                ['xendit_event_id' => $eventId],
                [
                    'payload' => $payload,
                    'signature_valid' => false,
                    'processed_at' => now(),
                    'processing_result' => WebhookProcessingResult::RejectedSignature->value,
                ]
            );

            return $log->wasRecentlyCreated ? WebhookProcessingResult::RejectedSignature : WebhookProcessingResult::Duplicate;
        }

        return DB::transaction(function () use ($payload, $eventId) {
            $existing = PaymentWebhookLog::where('xendit_event_id', $eventId)->lockForUpdate()->first();

            if ($existing !== null) {
                return WebhookProcessingResult::Duplicate;
            }

            $externalId = $payload['external_id'] ?? null;
            $invoice = $externalId !== null
                ? Invoice::withoutGlobalScopes()->where('invoice_number', $externalId)->first()
                : null;

            if ($invoice === null) {
                $this->log($eventId, $payload, WebhookProcessingResult::RejectedInvoiceNotFound);

                return WebhookProcessingResult::RejectedInvoiceNotFound;
            }

            $webhookAmount = isset($payload['amount']) ? (float) $payload['amount'] : null;

            // Exact match only — partial payment is explicitly deferred
            // this sprint (confirmed decision), so >= would silently accept
            // an underpayment as if it were the full grand_total.
            if ($webhookAmount === null || $webhookAmount !== (float) $invoice->grand_total) {
                $this->log($eventId, $payload, WebhookProcessingResult::RejectedAmountMismatch);

                return WebhookProcessingResult::RejectedAmountMismatch;
            }

            $payment = $this->resolvePaymentFor($invoice, $payload);

            if ($payment !== null) {
                $payment->update([
                    'status' => PaymentStatus::Paid,
                    'paid_at' => now(),
                    'raw_response' => $payload,
                ]);
            }

            $this->invoiceService->markPaid($invoice);

            $this->log($eventId, $payload, WebhookProcessingResult::Applied);

            return WebhookProcessingResult::Applied;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePaymentFor(Invoice $invoice, array $payload): ?Payment
    {
        $reference = $payload['id'] ?? null;

        return Payment::where('invoice_id', $invoice->id)
            ->when(
                $reference !== null,
                fn ($q) => $q->where('xendit_reference_id', $reference),
                fn ($q) => $q->where('status', PaymentStatus::Pending->value),
            )
            ->latest()
            ->first();
    }

    private function verifySignature(?string $signatureHeader): bool
    {
        // v0.3.5 Fase H: webhook token source moved from
        // config('services.xendit.callback_token')/.env to
        // payment_gateway_settings (encrypted) — see
        // PaymentGatewaySettingsService. Still a static shared-token
        // compare via hash_equals(), not HMAC — that part is unchanged.
        $expected = $this->gatewaySettings->getWebhookToken();

        if ($signatureHeader === null || blank($expected)) {
            return false;
        }

        return hash_equals((string) $expected, $signatureHeader);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveEventId(array $payload): string
    {
        return $payload['id']
            ?? $payload['event_id']
            ?? hash('sha256', json_encode($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(string $eventId, array $payload, WebhookProcessingResult $result): void
    {
        PaymentWebhookLog::create([
            'xendit_event_id' => $eventId,
            'payload' => $payload,
            'signature_valid' => true,
            'processed_at' => now(),
            'processing_result' => $result,
        ]);
    }
}
