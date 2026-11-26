<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Enums\WhatsappMessageStatus;
use App\Jobs\SendWhatsappGroupMessageJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Referrer;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSession;
use App\Services\Billing\PaymentInstructionsService;
use App\Services\Company\CompanyProfileService;
use App\Services\Payment\PaymentService;
use App\Support\Whatsapp\CustomerAutoSendDecision;
use App\Support\Whatsapp\InvoiceSendContext;
use App\Support\Whatsapp\WhatsappAttachment;
use App\Support\WhatsappPhone;
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
        private readonly CustomerAutoSendGate $gate,
    ) {}

    /**
     * Resolve template + render + log + dispatch, lewat "jalur pelanggan".
     * Returns null (no exception) when no template resolves at all — a seeding
     * gap, not something that should ever fail the caller's own transaction
     * (invoice markPaid, customer registration, etc).
     *
     * v0.13.5.3 — 2 parameter baru:
     *  - $attachment: lampiran media (PDF invoice) — satu pesan = PDF +
     *    caption (teks template ter-render). null = pesan teks biasa.
     *  - $context: HANYA diisi `InvoicePdfSendService` (kirim MANUAL). Bila
     *    diisi, panggilan ini MELEWATI saklar `customer_auto_notify_enabled`
     *    (bypass yang disengaja admin) DAN boleh mengganti penerima (Mode
     *    Uji), prefiks caption, tandai is_test, catat pelaku. SEMUA pemanggil
     *    OTOMATIS (4 event pelanggan lama + command) memanggil TANPA $context
     *    → tetap ber-guard. Dijaga `WhatsappManualBypassArchitectureTest`.
     */
    public function buildAndQueue(
        WhatsappEventType $eventType,
        Customer $customer,
        ?Invoice $invoice = null,
        ?WhatsappAttachment $attachment = null,
        ?InvoiceSendContext $context = null,
    ): ?WhatsappMessageLog {
        // Jalur MANUAL (context != null) — SATU-SATUNYA bypass gerbang
        // (InvoicePdfSendService). Perilaku tak berubah: boleh ganti penerima
        // (Mode Uji), prefiks caption, is_test, catat pelaku. Dijaga
        // WhatsappManualBypassArchitectureTest.
        if ($context !== null) {
            return $this->doCreateAndDispatch($eventType, $customer, $invoice, $attachment, $context);
        }

        // Jalur OTOMATIS — lewat CustomerAutoSendGate (v0.28.2, titik otoritatif
        // tunggal). Kembalikan HANYA log (kompatibel pemanggil lama yang cuma
        // butuh ?log, mis. RegistrationService); pemakai yang perlu alasan
        // (sweep/reminder) memanggil buildAndQueueGated().
        return $this->buildAndQueueGated($eventType, $customer, $invoice, $attachment)->log;
    }

    /**
     * v0.28.2 (T2) — jalur OTOMATIS dengan HASIL BERALASAN. Satu-satunya cara
     * jalur otomatis mengantre, selalu lewat gerbang. `$fromDeferral` dipakai
     * command whatsapp:process-deferred-registered saat mengevaluasi ulang
     * deferral customer_registered (jangan catat Deferred berulang).
     */
    public function buildAndQueueGated(
        WhatsappEventType $eventType,
        Customer $customer,
        ?Invoice $invoice = null,
        ?WhatsappAttachment $attachment = null,
        bool $fromDeferral = false,
    ): CustomerAutoSendDecision {
        return $this->gate->process(
            $eventType,
            $customer,
            $invoice,
            fn (int $delaySeconds): ?WhatsappMessageLog => $this->doCreateAndDispatch($eventType, $customer, $invoice, $attachment, null, $delaySeconds),
            // Predikat template-aktif (pra-rilis item 2) — dicek gerbang SEBELUM
            // Mode Uji. Resolve ringan (query ter-index); doCreateAndDispatch
            // me-resolve ulang saat benar-benar antre (jalur non-uji) — biaya
            // query kedua dapat diabaikan pada volume everyMinute ini.
            fn (): bool => $this->templateService->resolve($eventType, $customer->tenant_id, $customer->reseller_id) !== null,
            $fromDeferral,
        );
    }

    /**
     * Tail "jalur pelanggan": resolve template + render + log + dispatch.
     * Dipanggil langsung (manual) atau sebagai closure "antre sungguhan" oleh
     * gerbang (otomatis). Return null = template nonaktif/belum ada.
     */
    private function doCreateAndDispatch(
        WhatsappEventType $eventType,
        Customer $customer,
        ?Invoice $invoice,
        ?WhatsappAttachment $attachment,
        ?InvoiceSendContext $context,
        int $delaySeconds = 0,
    ): ?WhatsappMessageLog {
        $template = $this->templateService->resolve($eventType, $customer->tenant_id, $customer->reseller_id);

        if ($template === null) {
            Log::warning("WhatsappGatewayService: no active template resolved for {$eventType->value} (tenant_id={$customer->tenant_id}, reseller_id={$customer->reseller_id}) — skipping send.");

            return null;
        }

        $variables = $this->buildVariables($eventType, $customer, $invoice);
        $rendered = ($context?->captionPrefix ?? '').$this->templateService->render($template->content, $variables);

        // Mode Uji (v0.13.5.3) — penerima DIGANTI ke nomor uji (hanya jalur
        // manual yang mengisi context); default = nomor pelanggan asli.
        $recipientRaw = $context?->recipientOverride ?? $customer->phone_number;

        $log = WhatsappMessageLog::create([
            'tenant_id' => $customer->tenant_id,
            'reseller_id' => $customer->reseller_id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'phone_number' => WhatsappPhone::normalize($recipientRaw),
            'event_type' => $eventType,
            'template_id' => $template->id,
            'sent_by_user_id' => $context?->sentByUserId,
            'rendered_content' => $rendered,
            'attachment' => $attachment?->toArray(),
            'is_test' => $context?->isTest ?? false,
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ]);

        $sessionKey = WhatsappSession::sessionKeyFor($customer->reseller_id);

        // v0.28.4 (SEBAR-BATCH, D1) — jeda batch disuntik di SINI lewat ->delay()
        // saat dispatch (jalur OTOMATIS saja: $context null + delay dari gerbang/
        // planner). Jalur MANUAL (context != null) selalu delaySeconds=0 →
        // tak pernah di-delay batch (K14). delay(0) = segera, perilaku lama.
        SendWhatsappMessageJob::dispatch($log->id)
            ->onQueue('whatsapp-'.$sessionKey)
            ->delay($delaySeconds);

        return $log;
    }

    /**
     * v0.12.1 — jalur generic untuk pesan yang penerimanya BUKAN pelanggan
     * (Referrer, Technician, ...). Diekstrak dari
     * `buildAndQueueForReferrer()` supaya `App\Services\Shared\
     * ActionOtpService` bisa mengirim OTP ke penerima apa pun tanpa jalur
     * WA per-tipe sendiri.
     *
     * Penerima non-pelanggan tidak punya reseller, jadi template di-resolve
     * di level ISP (`reseller_id` null) dan pesan selalu diantre lewat sesi
     * "direct". `$variables` sudah jadi — pemanggil yang merakit
     * `recipient_name`/`company_name` dsb sesuai bentuk penerimanya.
     * `$relatedCustomer` opsional dipakai hanya untuk jejak
     * (`whatsapp_message_logs.customer_id`); pesan tetap dikirim ke `$phone`.
     *
     * Returns null (tanpa exception) kalau template belum di-seed untuk
     * tenant ini — pemanggil harus memperlakukan itu sebagai "pesan gagal
     * dikirim", bukan menelannya diam.
     *
     * `$resellerId` (2026-09-15, default null = perilaku lama) — SESI mana
     * yang dipakai mengirim. Default null berarti sesi "direct" (perilaku
     * asli method ini, tetap dipakai `buildAndQueueForReferrer()`/
     * `ActionOtpService` — Referrer tidak punya reseller). Diisi eksplisit
     * saat pemanggil PERLU mengirim lewat sesi reseller TERTENTU — satu-
     * satunya pemakai saat ini: notifikasi `duplicate_session_attempt`
     * (WhatsappSessionService), yang WAJIB terkirim lewat session LAMA
     * yang genuinely masih terhubung ke nomor itu (bisa jadi milik
     * reseller mana pun, bukan selalu 'direct').
     *
     * @param  array<string, string|int|null>  $variables
     */
    public function buildAndQueueForRecipient(
        WhatsappEventType $eventType,
        int $tenantId,
        string $phone,
        array $variables,
        ?Customer $relatedCustomer = null,
        ?int $resellerId = null,
        ?WhatsappAttachment $attachment = null,
    ): ?WhatsappMessageLog {
        $template = $this->templateService->resolve($eventType, $tenantId, $resellerId);

        if ($template === null) {
            Log::warning("WhatsappGatewayService: no active template resolved for {$eventType->value} (tenant_id={$tenantId}, reseller_id={$resellerId}, recipient path) — skipping send.");

            return null;
        }

        $rendered = $this->templateService->render($template->content, $variables);

        $log = WhatsappMessageLog::create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'customer_id' => $relatedCustomer?->id,
            'invoice_id' => null,
            'phone_number' => WhatsappPhone::normalize($phone),
            'event_type' => $eventType,
            'template_id' => $template->id,
            'rendered_content' => $rendered,
            'attachment' => $attachment?->toArray(),
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ]);

        $sessionKey = WhatsappSession::sessionKeyFor($resellerId);

        SendWhatsappMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);

        return $log;
    }

    /**
     * v0.26.4 — kembaran `buildAndQueueForRecipient()`, TAPI penerimanya
     * GRUP (bukan nomor individu manapun). Keputusan Opsi A eksplisit
     * dikonfirmasi Agung: KOLOM `whatsapp_message_logs.phone_number`
     * DIPAKAI ULANG untuk menyimpan JID grup MENTAH — bukan kolom baru
     * `group_jid` (tanpa migration). `$groupJid` diisi APA ADANYA, TIDAK
     * PERNAH lewat `WhatsappPhone::normalize()` (fungsi itu khusus nomor
     * telepon individu — akan MERUSAK string JID grup kalau dipaksakan,
     * strip semua karakter non-digit). Lihat docblock
     * `WhatsappMessageLog::$phone_number` untuk penjelasan lengkap.
     *
     * Dispatch `SendWhatsappGroupMessageJob` (BUKAN `SendWhatsappMessageJob`)
     * — job terpisah yang POST ke endpoint Go `/send-group`, bukan `/send`.
     * `relatedCustomer` sengaja TIDAK ada di signature ini — notifikasi
     * grup (mis. "WO baru tersedia") secara alami tidak terikat SATU
     * customer tertentu untuk keperluan tampilan Antrian WA, beda dari
     * `buildAndQueueForRecipient()` yang punya kasus 1-customer-1-pesan.
     *
     * v0.13.4.4 — param `$sessionKey` (dulu `?int $resellerId`): sesi milik
     * grup ('direct' | reseller_id string), dari WhatsappNotificationGroup.
     * Antre di queue `whatsapp-{session_key}` itu, reseller_id log/template
     * override diturunkan dari situ.
     *
     * @param  array<string, string|int|null>  $variables
     */
    public function buildAndQueueForGroup(
        WhatsappEventType $eventType,
        int $tenantId,
        string $groupJid,
        array $variables,
        string $sessionKey = 'direct',
        ?WhatsappAttachment $attachment = null,
    ): ?WhatsappMessageLog {
        // v0.13.4.4 — kirim grup SELALU lewat sesi milik grup (session_key),
        // bukan reseller_id WO. reseller_id (untuk log + resolve template
        // override) diturunkan DARI session_key: 'direct' -> null, numerik ->
        // reseller_id. Menutup bug laten "grup direct di-antre lewat sesi
        // reseller yang belum tentu anggota grup".
        $resellerId = $sessionKey === 'direct' ? null : (int) $sessionKey;
        $template = $this->templateService->resolve($eventType, $tenantId, $resellerId);

        if ($template === null) {
            Log::warning("WhatsappGatewayService: no active template resolved for {$eventType->value} (tenant_id={$tenantId}, reseller_id={$resellerId}, group path) — skipping send.");

            return null;
        }

        $rendered = $this->templateService->render($template->content, $variables);

        $log = WhatsappMessageLog::create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'customer_id' => null,
            'invoice_id' => null,
            'phone_number' => $groupJid,
            'event_type' => $eventType,
            'template_id' => $template->id,
            'rendered_content' => $rendered,
            'attachment' => $attachment?->toArray(),
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ]);

        SendWhatsappGroupMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);

        return $log;
    }

    /**
     * v0.13.5.1 — N lampiran = N log BERURUTAN ke satu grup (satu pesan =
     * satu lampiran = satu job). Caption (teks template ter-render) hanya
     * pada lampiran PERTAMA; sisanya rendered_content kosong (pola "6 foto,
     * teks di foto pertama" untuk .3.2). FIFO dijaga oleh queue
     * whatsapp-{session_key} + satu worker (dispatch berurutan).
     *
     * @param  list<WhatsappAttachment>  $attachments
     * @return list<WhatsappMessageLog>
     */
    public function queueMediaBatchForGroup(
        WhatsappEventType $eventType,
        int $tenantId,
        string $groupJid,
        array $variables,
        array $attachments,
        string $sessionKey = 'direct',
    ): array {
        if ($attachments === []) {
            return [];
        }

        // v0.13.4.4 — lihat buildAndQueueForGroup(): session_key grup, bukan reseller_id WO.
        $resellerId = $sessionKey === 'direct' ? null : (int) $sessionKey;
        $template = $this->templateService->resolve($eventType, $tenantId, $resellerId);
        if ($template === null) {
            Log::warning("WhatsappGatewayService: no active template resolved for {$eventType->value} (tenant_id={$tenantId}, group media batch) — skipping.");

            return [];
        }

        $caption = $this->templateService->render($template->content, $variables);
        $logs = [];

        foreach (array_values($attachments) as $i => $attachment) {
            $log = WhatsappMessageLog::create([
                'tenant_id' => $tenantId,
                'reseller_id' => $resellerId,
                'customer_id' => null,
                'invoice_id' => null,
                'phone_number' => $groupJid,
                'event_type' => $eventType,
                'template_id' => $template->id,
                // v0.13.4.3.2 — caption PER-ITEM bila lampiran membawanya
                // sendiri (mis. label "1/6 Speedtest · WO #..."); fallback
                // pola lama (caption template hanya di lampiran pertama)
                // kalau attachment->caption null — menjaga perilaku v0.13.5.1.
                'rendered_content' => $attachment->caption ?? ($i === 0 ? $caption : ''),
                'attachment' => $attachment->toArray(),
                'status' => WhatsappMessageStatus::Queued,
                'queued_at' => now(),
            ]);

            SendWhatsappGroupMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);
            $logs[] = $log;
        }

        return $logs;
    }

    /**
     * v0.22.4 — kembaran `buildAndQueueForRecipient()`, TAPI SENGAJA
     * MELEWATI `WhatsappTemplateService::resolve()`/`render()` sepenuhnya —
     * `$rawContent` dipakai APA ADANYA sebagai `rendered_content`,
     * `template_id` selalu `null`. Satu-satunya pemakai saat ini: pesan
     * password polos `StaffService::create()`
     * (`WhatsappEventType::StaffInitialPasswordValue`) — isi pesan itu
     * WAJIB persis string password tanpa karakter/format lain menempel,
     * jadi tidak boleh melalui template yang bisa diedit admin (lihat
     * docblock event type-nya). Tidak pernah `null` (beda dari
     * `buildAndQueueForRecipient()` yang bisa `null` kalau template belum
     * di-seed) — tidak ada template untuk gagal di-resolve di sini.
     */
    public function queueRawForRecipient(
        WhatsappEventType $eventType,
        int $tenantId,
        string $phone,
        string $rawContent,
        ?int $resellerId = null,
    ): WhatsappMessageLog {
        $log = WhatsappMessageLog::create([
            'tenant_id' => $tenantId,
            'reseller_id' => $resellerId,
            'customer_id' => null,
            'invoice_id' => null,
            'phone_number' => WhatsappPhone::normalize($phone),
            'event_type' => $eventType,
            'template_id' => null,
            'rendered_content' => $rawContent,
            'status' => WhatsappMessageStatus::Queued,
            'queued_at' => now(),
        ]);

        $sessionKey = WhatsappSession::sessionKeyFor($resellerId);

        SendWhatsappMessageJob::dispatch($log->id)->onQueue('whatsapp-'.$sessionKey);

        return $log;
    }

    /**
     * v0.9.6 — jalur untuk pesan yang penerimanya REFERRER, bukan
     * pelanggan (satu-satunya pemakai: WhatsappEventType::ReferrerActionOtp
     * lewat ReferrerActionOtpService). Sejak v0.12.1 ini thin wrapper di
     * atas `buildAndQueueForRecipient()` — dipertahankan supaya pemanggil
     * lama tidak berubah dan bentuk variabel Referrer terdokumentasi.
     *
     * @param  array<string, string|int|null>  $extraVariables
     */
    public function buildAndQueueForReferrer(
        WhatsappEventType $eventType,
        Referrer $referrer,
        array $extraVariables = [],
        ?Customer $relatedCustomer = null,
    ): ?WhatsappMessageLog {
        return $this->buildAndQueueForRecipient(
            $eventType,
            $referrer->tenant_id,
            $referrer->phone,
            array_merge([
                'referrer_name' => $referrer->name,
                'company_name' => $referrer->tenant?->name,
                'customer_name' => $relatedCustomer?->name,
            ], $extraVariables),
            $relatedCustomer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariables(WhatsappEventType $eventType, Customer $customer, ?Invoice $invoice): array
    {
        // v0.13.5.4 (TAHAP A) — caption pelanggan WAJIB memakai SUMBER YANG SAMA
        // dengan PDF invoice: CompanyProfileService::resolveFor (brand/legal +
        // override CS reseller), BUKAN nama tenant mentah. Dulu {company_name}
        // menampilkan nama tenant ("ISP Demo") sementara PDF menampilkan brand/
        // legal ("TOKEN WIFI"/"PT ..."); sekarang keduanya sinkron.
        $company = app(CompanyProfileService::class)->resolveFor($customer->tenant_id, $customer->reseller_id);
        $companyName = $company['brand_name'] !== ''
            ? $company['brand_name']
            : ($company['legal_name'] !== '' ? $company['legal_name'] : (string) ($customer->tenant?->name ?? ''));

        $variables = [
            'customer_name' => $customer->name,
            'customer_id' => $customer->id,
            'invoice_number' => $invoice?->invoice_number,
            'due_date' => $invoice?->due_date?->format('d/m/Y'),
            // v0.13.5.5 (T3) — {paid_date} untuk caption kuitansi (event
            // PaymentReceived). null bila invoice belum lunas / tak ada paid_at.
            'paid_date' => $invoice?->paid_at?->format('d/m/Y'),
            'total_amount' => $invoice !== null ? 'Rp'.number_format((float) $invoice->grand_total, 0, ',', '.') : null,
            'package_name' => $invoice?->subscription?->name,
            'company_name' => $companyName,
            'payment_link' => null,
            // v0.13.5.3 — {period} = bulan periode invoice; {cs_number} = nomor
            // CS efektif (override reseller → CS tenant → env), dari resolveFor
            // (resolveCs yang sama) agar sinkron dengan PDF.
            'period' => $invoice?->period_start?->translatedFormat('F Y'),
            'cs_number' => $company['cs'],
            // v0.13.5.4 — {payment_instructions} = teks ringkas cara bayar
            // (render-only, TANPA Xendit). Diisi hanya untuk invoice_issued di
            // bawah; null untuk event lain (renderer menangani var kosong).
            'payment_instructions' => null,
        ];

        // Confirmed decision (v0.4.0): generate a fresh Xendit Invoice link
        // on demand at reminder time — no invoice_url is persisted anywhere
        // ahead of time (Payment rows are only ever created when a payment
        // attempt actually starts).
        if ($eventType === WhatsappEventType::InvoiceDueReminder && $invoice !== null) {
            $variables['payment_link'] = $this->resolvePaymentLink($invoice);
        }

        // v0.13.5.4 — instruksi pembayaran untuk caption invoice_issued.
        // PaymentInstructionsService::captionText TIDAK PERNAH memanggil Xendit.
        if ($eventType === WhatsappEventType::InvoiceIssued && $invoice !== null) {
            $variables['payment_instructions'] = app(PaymentInstructionsService::class)->captionText($invoice);
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
