<?php

namespace App\Services\Whatsapp;

use App\Enums\CustomerAutoSendResult;
use App\Models\CustomerAutoSendBreaker;
use App\Models\CustomerAutoSendCursor;
use App\Models\CustomerAutoSendDecisionLog;
use App\Models\CustomerAutoSendDeferral;
use App\Models\CustomerAutoSendSettingLog;
use App\Models\WorkOrderDispatchSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v0.28.5 (UI tab "Kirim Otomatis") — SATU-SATUNYA tempat logika pengaturan
 * kirim otomatis pelanggan: menulis field ke work_order_dispatch_settings,
 * mencatat jejak APPEND-ONLY (satu baris per FIELD yang berubah) ke
 * customer_auto_send_setting_logs, meneruskan "Lanjutkan pemutus" ke
 * CustomerAutoSendGate::resume (mesin yang sama dipakai
 * whatsapp:auto-send-resume), dan menghitung indikator read-only.
 *
 * Komponen Livewire HANYA memanggil service ini (validasi form tetap di
 * komponen). Service ini TIDAK mengubah perilaku gerbang/orkestrator/batch —
 * hanya menyetel nilai yang mereka baca + membaca jejak/keputusan mereka.
 */
class CustomerAutoSendSettingsService
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(private CustomerAutoSendGate $gate) {}

    public function settings(int $tenantId): WorkOrderDispatchSettings
    {
        return WorkOrderDispatchSettings::forTenant($tenantId);
    }

    // ── Simpan per kartu (audit satu baris per field berubah) ─────────────

    public function saveCustomerAutoNotify(int $tenantId, bool $enabled, ?int $userId): void
    {
        $settings = $this->settings($tenantId);
        $this->applyWithAudit($settings, [
            'customer_auto_notify_enabled' => $enabled,
        ], $userId);
    }

    public function saveTestMode(int $tenantId, bool $enabled, ?string $recipientNormalized, ?int $userId): void
    {
        $settings = $this->settings($tenantId);
        $this->applyWithAudit($settings, [
            'invoice_pdf_test_mode_enabled' => $enabled,
            'invoice_pdf_test_recipient' => ($recipientNormalized === null || $recipientNormalized === '') ? null : $recipientNormalized,
        ], $userId);
    }

    public function saveInvoiceAutoSend(int $tenantId, bool $enabled, ?string $from, ?int $userId): void
    {
        $settings = $this->settings($tenantId);

        $wasEnabled = (bool) $settings->invoice_pdf_auto_send_enabled;
        $turningOn = $enabled && ! $wasEnabled;

        // enabled_at = batas bawah "hanya invoice baru"; diisi saat OFF->ON,
        // dibiarkan selain itu. Internal (bukan field yang diaudit).
        $extra = [
            'invoice_pdf_auto_send_enabled_at' => $turningOn ? now() : $settings->invoice_pdf_auto_send_enabled_at,
        ];

        $this->applyWithAudit($settings, [
            'invoice_pdf_auto_send_enabled' => $enabled,
            'invoice_pdf_auto_send_from' => ($from === null || $from === '') ? null : $from,
        ], $userId, $extra);
    }

    /**
     * @param  list<int>  $days  sudah tersanitasi oleh pemanggil (DueReminderDays::sanitize).
     */
    public function saveReminderDays(int $tenantId, array $days, ?int $userId): void
    {
        $settings = $this->settings($tenantId);
        $this->applyWithAudit($settings, [
            'due_reminder_days' => array_values($days),
        ], $userId);
    }

    public function saveVolumeControls(int $tenantId, int $cap, string $windowStart, string $windowEnd, int $breakerThreshold, ?int $userId): void
    {
        $settings = $this->settings($tenantId);
        $this->applyWithAudit($settings, [
            'customer_auto_send_daily_cap' => $cap,
            'customer_auto_send_window_start' => $windowStart,
            'customer_auto_send_window_end' => $windowEnd,
            'customer_auto_send_breaker_threshold' => $breakerThreshold,
        ], $userId);
    }

    /**
     * Terapkan $changes ke settings, catat SATU baris audit per field yang
     * benar-benar berubah (bandingkan nilai ter-normalisasi string), lalu satu
     * update. $extra = field internal yang di-update tapi TIDAK diaudit.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $extra
     */
    private function applyWithAudit(WorkOrderDispatchSettings $settings, array $changes, ?int $userId, array $extra = []): void
    {
        $payload = $extra;
        $auditRows = [];

        foreach ($changes as $field => $newValue) {
            $before = $this->stringify($settings->getAttribute($field));
            $after = $this->stringify($newValue);

            if ($before === $after) {
                continue; // tidak berubah → tidak ditulis, tidak diaudit
            }

            $payload[$field] = $newValue;
            $auditRows[] = [
                'tenant_id' => $settings->tenant_id,
                'field' => $field,
                'before' => $before,
                'after' => $after,
                'user_id' => $userId,
                'created_at' => now(),
            ];
        }

        if ($payload === []) {
            return; // tidak ada perubahan apa pun
        }

        DB::transaction(function () use ($settings, $payload, $auditRows) {
            $settings->update($payload);
            foreach ($auditRows as $row) {
                CustomerAutoSendSettingLog::create($row);
            }
        });
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }

        return (string) $value;
    }

    // ── Pemutus (sama dengan whatsapp:auto-send-resume) ──────────────────

    /**
     * Tutup pemutus untuk SEMUA sesi tenant (mesin sama dengan command
     * whatsapp:auto-send-resume). Mengembalikan jumlah sesi yang dilanjutkan.
     * Pelaku tercatat di breaker.resumed_by (via gate->resume) + log.
     */
    public function resumeAll(int $tenantId, ?int $userId): int
    {
        $breakers = CustomerAutoSendBreaker::query()->where('tenant_id', $tenantId)->get();
        $resumed = 0;

        foreach ($breakers as $breaker) {
            if (! $breaker->is_open && $breaker->consecutive_failures === 0) {
                continue;
            }

            $resellerId = $breaker->session_key === 'direct' ? null : (int) $breaker->session_key;
            $this->gate->resume($tenantId, $resellerId, $userId);
            $resumed++;
        }

        Log::info('customer-auto-send: pemutus dilanjutkan', [
            'tenant_id' => $tenantId,
            'by_user_id' => $userId,
            'sesi_dilanjutkan' => $resumed,
        ]);

        return $resumed;
    }

    /**
     * Status pemutus tenant untuk ditampilkan: daftar sesi yang terbuka +
     * boolean any_open. Sesi tanpa kegagalan tidak ditampilkan.
     *
     * @return array{any_open: bool, open: Collection<int, CustomerAutoSendBreaker>}
     */
    public function breakerStatus(int $tenantId): array
    {
        $open = CustomerAutoSendBreaker::query()
            ->where('tenant_id', $tenantId)
            ->where('is_open', true)
            ->orderBy('opened_at')
            ->get();

        return ['any_open' => $open->isNotEmpty(), 'open' => $open];
    }

    // ── Indikator read-only ──────────────────────────────────────────────

    /**
     * @return array{queued_today:int, cap:int, decision_counts:array<string,int>, deferrals_pending:int, next_cursor_slot:?string}
     */
    public function indicators(int $tenantId): array
    {
        $settings = WorkOrderDispatchSettings::query()->where('tenant_id', $tenantId)->first();

        $counts = CustomerAutoSendDecisionLog::query()
            ->toBase() // hindari hidrasi enum-cast saat groupBy (gotcha CLAUDE.md)
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', $this->wibToday())
            ->selectRaw('result, COUNT(*) AS c')
            ->groupBy('result')
            ->pluck('c', 'result')
            ->map(fn ($c) => (int) $c)
            ->all();

        $deferralsPending = CustomerAutoSendDeferral::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CustomerAutoSendDeferral::STATUS_PENDING)
            ->count();

        $cursor = CustomerAutoSendCursor::query()
            ->where('tenant_id', $tenantId)
            ->where('session_key', 'direct')
            ->first();

        $nextSlot = null;
        if ($cursor?->next_slot_at !== null && $cursor->next_slot_at->isFuture()) {
            $nextSlot = $cursor->next_slot_at->setTimezone(self::TIMEZONE)->format('d M Y H:i:s');
        }

        return [
            'queued_today' => $this->gate->queuedToday($tenantId),
            'cap' => $this->gate->cap($settings),
            'decision_counts' => $counts,
            'deferrals_pending' => $deferralsPending,
            'next_cursor_slot' => $nextSlot,
        ];
    }

    /**
     * 20 keputusan terakhir (WIB tak dibatasi hari) — waktu, jalur, pelanggan,
     * NOMOR INVOICE, result. Eager-load customer + invoice.
     *
     * @return Collection<int, CustomerAutoSendDecisionLog>
     */
    public function recentDecisions(int $tenantId, int $limit = 20): Collection
    {
        return CustomerAutoSendDecisionLog::query()
            ->with(['customer:id,name', 'invoice:id,invoice_number'])
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * 10 perubahan pengaturan terakhir (jejak audit).
     *
     * @return Collection<int, CustomerAutoSendSettingLog>
     */
    public function recentAuditLogs(int $tenantId, int $limit = 10): Collection
    {
        return CustomerAutoSendSettingLog::query()
            ->with('user:id,name')
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function wibToday(): string
    {
        return now()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * Label field untuk tampilan jejak audit.
     */
    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'customer_auto_notify_enabled' => 'Notifikasi Otomatis ke Pelanggan',
            'invoice_pdf_test_mode_enabled' => 'Mode Uji Kirim Invoice',
            'invoice_pdf_test_recipient' => 'Nomor Uji',
            'invoice_pdf_auto_send_enabled' => 'Kirim Invoice Otomatis',
            'invoice_pdf_auto_send_from' => 'Mulai dari tanggal',
            'due_reminder_days' => 'Jadwal hari pengingat',
            'customer_auto_send_daily_cap' => 'Plafon harian',
            'customer_auto_send_window_start' => 'Jendela jam (awal)',
            'customer_auto_send_window_end' => 'Jendela jam (akhir)',
            'customer_auto_send_breaker_threshold' => 'Ambang pemutus',
            default => $field,
        };
    }

    /**
     * Daftar hasil (CustomerAutoSendResult) + label untuk legend indikator.
     *
     * @return array<string, string>
     */
    public static function resultLabels(): array
    {
        $out = [];
        foreach (CustomerAutoSendResult::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
