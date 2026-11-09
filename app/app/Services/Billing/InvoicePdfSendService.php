<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\WhatsappEventType;
use App\Enums\WhatsappSessionStatus;
use App\Exceptions\InvoicePdfSendException;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Services\Whatsapp\WhatsappTemplateService;
use App\Support\Whatsapp\InvoiceSendContext;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappPhone;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * v0.13.5.3 — kirim PDF invoice MANUAL ke pelanggan (satu pesan: PDF +
 * caption). SATU-SATUNYA tempat `InvoiceSendContext` dibangun — artinya
 * satu-satunya jalur yang MELEWATI saklar `customer_auto_notify_enabled`
 * (dijaga `WhatsappManualBypassArchitectureTest`). Kirim OTOMATIS (saat
 * invoice terbit) BUKAN scope sub-versi ini (v0.13.5.3.1 terpisah) —
 * JANGAN memanggil service ini dari command/sweep/hook.
 *
 * Semua penegakan di sini (status, nomor, template aktif, sesi connected,
 * Mode Uji, anti-spam kirim ulang) — UI hanya menampilkan/mengonfirmasi.
 */
class InvoicePdfSendService
{
    /** Jeda minimal kirim ulang untuk invoice yang sama (anti-spam). */
    public const RESEND_COOLDOWN_MINUTES = 10;

    public function __construct(
        private readonly InvoicePdfService $pdfService,
        private readonly WhatsappGatewayService $gateway,
        private readonly WhatsappTemplateService $templateService,
    ) {}

    /**
     * Kirim (atau kirim ulang) PDF invoice ke pelanggan. Melempar
     * InvoicePdfSendException (bisnis, aman ditampilkan) atau
     * AuthorizationException (izin) — tidak pernah mengirim WA sendiri, hanya
     * mengantre lewat jalur pelanggan ber-attachment.
     */
    public function sendManually(Invoice $invoice, User $actor, bool $resend = false): WhatsappMessageLog
    {
        // 1. Izin — kirim = aksi "manage" invoice (permission invoices.manage).
        if (Gate::forUser($actor)->denies('manage', Invoice::class)) {
            throw new AuthorizationException('Anda tidak punya izin mengirim invoice.');
        }

        // 2. Status: Pending/Overdue/Paid saja (bukan Draft/Cancelled).
        if (! in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)) {
            throw new InvoicePdfSendException(
                "Invoice berstatus {$invoice->status->label()} tidak bisa dikirim (hanya Pending/Overdue/Lunas)."
            );
        }

        // 3. Pelanggan + nomor valid.
        $customer = $invoice->customer;
        if ($customer === null) {
            throw new InvoicePdfSendException('Invoice ini tidak terkait pelanggan — tidak bisa dikirim.');
        }

        $customerPhone = WhatsappPhone::normalize($customer->phone_number);
        if (! $this->isValidWaNumber($customerPhone)) {
            throw new InvoicePdfSendException('Nomor WhatsApp pelanggan kosong atau tidak valid.');
        }

        // 4. Template aktif (nonaktif/belum ada = tolak jelas, bukan diam).
        $template = $this->templateService->resolve(WhatsappEventType::InvoiceIssued, $customer->tenant_id, $customer->reseller_id);
        if ($template === null) {
            throw new InvoicePdfSendException(
                "Template WhatsApp 'Tagihan Terbit' tidak aktif atau belum ada. Aktifkan dulu di menu Template WA."
            );
        }

        // 5. Sesi tujuan connected (sesi dari reseller_id pelanggan). Logged
        //    out/qr_pending/disconnected => TIDAK diantre.
        $session = $this->sessionForCustomer($invoice);
        $sessionKey = WhatsappSession::sessionKeyFor($customer->reseller_id);
        if ($session === null || $session->status !== WhatsappSessionStatus::Connected) {
            $statusText = $session?->status?->value ?? 'belum ada';
            throw new InvoicePdfSendException(
                "Sesi WhatsApp ($sessionKey) belum terhubung (status: {$statusText}). Hubungkan dulu di Konfig WA Gateway."
            );
        }

        // 6. Anti-spam kirim ulang: kalau sudah pernah dikirim (uji/non-uji),
        //    butuh $resend=true; dan jeda minimal untuk invoice yang sama.
        $lastSend = $this->lastSendFor($invoice);
        if ($lastSend !== null) {
            if (! $resend) {
                $when = $lastSend->created_at?->translatedFormat('d M Y H:i') ?? 'sebelumnya';
                throw new InvoicePdfSendException(
                    "Invoice ini sudah pernah dikirim ($when). Gunakan Kirim Ulang bila memang perlu."
                );
            }

            $remaining = $this->resendRemainingMinutes($lastSend->created_at);
            if ($remaining > 0) {
                throw new InvoicePdfSendException(
                    "Terlalu cepat. Tunggu {$remaining} menit sebelum mengirim ulang invoice ini."
                );
            }
        }

        // 7. Mode Uji per tenant — penerima diganti ke nomor uji; pelanggan
        //    asli TIDAK menerima apa pun.
        $settings = WorkOrderDispatchSettings::forTenant($customer->tenant_id);
        $recipientOverride = null;
        $isTest = false;
        $captionPrefix = '';

        if ($settings->invoice_pdf_test_mode_enabled) {
            $testRecipient = WhatsappPhone::normalize($settings->invoice_pdf_test_recipient);
            if (! $this->isValidWaNumber($testRecipient)) {
                throw new InvoicePdfSendException(
                    'Mode Uji aktif tapi nomor uji belum diisi/valid. Isi nomor uji di Konfig WA Gateway.'
                );
            }

            $recipientOverride = $testRecipient;
            $isTest = true;
            $captionPrefix = "[UJI untuk: {$customer->name} - {$invoice->invoice_number}]".PHP_EOL;
        }

        // 8. Render fresh -> store -> lampiran (kepemilikan tenant dijamin
        //    lewat Invoice induk).
        $this->pdfService->store($invoice);
        $attachment = WhatsappAttachment::fromInvoicePdf($invoice, $invoice->tenant_id);

        // 9. Kirim lewat JALUR PELANGGAN dengan context (bypass saklar +
        //    recipient/prefix/is_test/pelaku).
        $context = new InvoiceSendContext(
            recipientOverride: $recipientOverride,
            isTest: $isTest,
            captionPrefix: $captionPrefix,
            sentByUserId: $actor->id,
        );

        $log = $this->gateway->buildAndQueue(
            WhatsappEventType::InvoiceIssued,
            $customer,
            $invoice,
            $attachment,
            $context,
        );

        if ($log === null) {
            // Defensive — template sudah dicek aktif di atas, seharusnya tak terjadi.
            throw new InvoicePdfSendException('Gagal mengantrekan pesan invoice.');
        }

        return $log;
    }

    /**
     * Data untuk modal konfirmasi UI — dihitung dengan logika yang SAMA dengan
     * sendManually() supaya banner "nomor tujuan" cocok dengan yang benar-benar
     * dipakai service.
     *
     * @return array{
     *     customer_name: string,
     *     customer_phone: string,
     *     recipient_display: string,
     *     test_mode: bool,
     *     test_recipient: string,
     *     test_recipient_valid: bool,
     *     already_sent_at: ?CarbonInterface,
     *     last_is_test: bool,
     *     last_status: ?string,
     * }
     */
    public function previewFor(Invoice $invoice): array
    {
        $customer = $invoice->customer;
        $customerPhone = $customer !== null ? WhatsappPhone::normalize($customer->phone_number) : '';

        $settings = WorkOrderDispatchSettings::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->first();

        $testMode = (bool) ($settings->invoice_pdf_test_mode_enabled ?? false);
        $testRecipient = WhatsappPhone::normalize($settings->invoice_pdf_test_recipient ?? null);
        $testRecipientValid = $this->isValidWaNumber($testRecipient);

        $recipientDisplay = $testMode && $testRecipientValid ? $testRecipient : $customerPhone;

        // "Terkirim ke pelanggan" HANYA dari log NON-UJI.
        $lastRealSend = $this->lastSendFor($invoice, includeTest: false);
        $lastAny = $this->lastSendFor($invoice);

        return [
            'customer_name' => $customer?->name ?? '—',
            'customer_phone' => $customerPhone,
            'recipient_display' => $recipientDisplay,
            'invoice_number' => (string) $invoice->invoice_number,
            'total' => 'Rp'.number_format((float) $invoice->grand_total, 0, ',', '.'),
            'due_date' => $invoice->due_date?->format('d/m/Y') ?? '—',
            'status_label' => $invoice->status->label(),
            'sendable' => in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid], true),
            'test_mode' => $testMode,
            'test_recipient' => $testRecipient,
            'test_recipient_valid' => $testRecipientValid,
            'already_sent_at' => $lastRealSend?->sent_at ?? $lastRealSend?->created_at,
            'last_is_test' => (bool) ($lastAny?->is_test ?? false),
            'last_status' => $lastAny?->status?->value,
        ];
    }

    private function sessionForCustomer(Invoice $invoice): ?WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where(function ($query) use ($invoice) {
                $resellerId = $invoice->customer?->reseller_id;
                $resellerId === null
                    ? $query->whereNull('reseller_id')
                    : $query->where('reseller_id', $resellerId);
            })
            ->first();
    }

    private function lastSendFor(Invoice $invoice, bool $includeTest = true): ?WhatsappMessageLog
    {
        return WhatsappMessageLog::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->where('event_type', WhatsappEventType::InvoiceIssued->value)
            ->when(! $includeTest, fn ($query) => $query->where('is_test', false))
            ->latest('created_at')
            ->first();
    }

    private function resendRemainingMinutes(?CarbonInterface $lastAt): int
    {
        if ($lastAt === null) {
            return 0;
        }

        $elapsed = $lastAt->diffInMinutes(now());

        return (int) max(0, ceil(self::RESEND_COOLDOWN_MINUTES - $elapsed));
    }

    private function isValidWaNumber(string $normalized): bool
    {
        // normalize() menghasilkan 62 + sisa; nomor Indonesia valid minimal
        // ~10 digit total (62 + 8). Longgar, cukup menyaring kosong/sampah.
        return strlen($normalized) >= 10 && str_starts_with($normalized, '62');
    }
}
