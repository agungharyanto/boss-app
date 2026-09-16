<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.26.2 — per-TENANT (bukan platform-level singleton seperti
 * `payment_gateway_settings`/`whatsapp_gateway_settings`, dikonfirmasi
 * lewat investigasi Langkah 0: keduanya cuma 1 baris untuk seluruh
 * deployment ISP, tidak cocok untuk kebutuhan ini). Nama sengaja BUKAN
 * `wa_gateway_settings` — terlalu mirip `whatsapp_gateway_settings` yang
 * sudah ada padahal domain/scope-nya beda total (timing dispatch/reminder
 * Work Order, bukan rate-limit pengiriman WA) — dikonfirmasi Agung.
 *
 * `tenant_id` unique — satu baris settings per tenant, `firstOrCreate`
 * pattern (lihat `WorkOrderDispatchSettings::forTenant()`) mengisi default
 * kalau belum ada baris untuk tenant itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_dispatch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Berapa lama SEBELUM janji kunjungan (work_orders.scheduled_at)
            // sebuah WO "keluar"/dispatch ke teknisi. Disimpan dalam MENIT
            // (form UI menampilkannya dalam jam, dikonversi saat simpan) —
            // v0.26.1's docblock createFromSubscription() sudah menyebut
            // "2 jam" sebagai contoh, dikunci sebagai default di sini.
            $table->unsignedInteger('dispatch_offset_minutes')->default(120);

            // Jam harian ('HH:MM') command reminder mulai efektif jalan
            // untuk WO yang masih belum Completed sejak dispatch.
            $table->string('reminder_time', 5)->default('08:00');

            // Interval self-throttle command (menit) — dibaca command yang
            // di-schedule ->everyMinute(), dibandingkan dengan
            // last_dispatch_run_at di bawah supaya cadence SEBENARNYA
            // configurable per tenant tanpa redeploy.
            $table->unsignedSmallInteger('command_interval_minutes')->default(15);

            // Nama grup WA tujuan notifikasi dispatch (v0.26.3) — admin
            // isi manual, JID-nya (di bawah) baru bisa diisi setelah
            // kapabilitas kirim-ke-grup ada di gateway Go (v0.26.4).
            $table->string('wa_group_name')->nullable();
            $table->string('wa_group_jid')->nullable();

            // Self-throttle command — diupdate command SETELAH satu siklus
            // dispatch/reminder selesai, bukan di awal (supaya kegagalan
            // di tengah siklus tetap dicoba ulang di run berikutnya,
            // bukan diam-diam dianggap sudah jalan).
            $table->timestamp('last_dispatch_run_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_dispatch_settings');
    }
};
