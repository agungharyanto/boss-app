<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.28.3 (T3, A5) — himpunan HARI pengingat jatuh tempo per tenant.
 *
 * `due_reminder_days` = daftar JSON bilangan 0..7 (H-0..H-7). Default `[5, 0]`
 * = perilaku lama (H-5 dan H-0). Menggantikan lead-day H-0/H-5 yang dulu
 * HARD-CODE di SendWhatsappDueReminders. Dikonsumsi oleh
 * CustomerAutoSendOrchestrator (jalur terjadwal baru); command lama tetap
 * hard-code H-0/H-5 (manual-only, tak terjadwal — lihat routes/console.php).
 *
 * Validasi (minimal 1 hari bila diisi, tiap nilai 0..7, tolak []/8/-1)
 * ditegakkan App\Support\Whatsapp\DueReminderDays (dipakai orkestrator untuk
 * sanitasi + UI v0.28.5). Kolom nullable; baris existing di-backfill `[5, 0]`
 * agar eksplisit = perilaku lama (bukan mengandalkan null → default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->json('due_reminder_days')->nullable()->after('invoice_pdf_auto_send_from');
        });

        // Backfill eksplisit ke [5, 0] untuk semua baris yang sudah ada (perilaku
        // lama H-5/H-0) — jangan biarkan null di baris lama.
        DB::table('work_order_dispatch_settings')
            ->whereNull('due_reminder_days')
            ->update(['due_reminder_days' => json_encode([5, 0])]);
    }

    public function down(): void
    {
        Schema::table('work_order_dispatch_settings', function (Blueprint $table) {
            $table->dropColumn('due_reminder_days');
        });
    }
};
