<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.4 — Multi-Grup Notifikasi WA + Routing per Kategori.
 *
 * `whatsapp_notification_groups`: beberapa grup WA per tenant, tiap grup
 * TERIKAT ke `session_key` ('direct' | reseller_id string) — JID grup hanya
 * valid di sesi WA yang bergabung ke grup itu, jadi kirim ke grup SELALU
 * lewat sesi milik grup (menutup bug laten di mana pesan grup di-antre lewat
 * sesi reseller WO yang belum tentu anggota grup). UI v0.13.4.4 hanya
 * membuat grup 'direct'; kemampuan session_key reseller ada di model/resolver
 * tapi belum ada UI-nya (backlog, lihat ROADMAP).
 *
 * `whatsapp_group_routes`: kategori (string = nilai WhatsappEventType, mis.
 * 'work_order_completed') -> grup. Tidak ada baris / group_id null -> pakai
 * grup DEFAULT. Kategori baru = tambah const di WhatsappGroupCategory, TANPA
 * migrasi (kolom tidak berubah).
 *
 * Kolom lama `work_order_dispatch_settings.wa_group_jid`/`wa_group_name`
 * DIBIARKAN (deprecated) — migrasi data terpisah menyalin JID lama ke grup
 * default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // 'direct' atau (string) reseller_id — sama konvensi
            // WhatsappSession::sessionKeyFor(). String, bukan FK, karena
            // 'direct' bukan baris reseller manapun.
            $table->string('session_key')->default('direct');
            $table->string('jid');
            $table->string('label');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Grup sama tidak boleh dobel per tenant.
            $table->unique(['tenant_id', 'jid']);
        });

        // Hanya 1 default per (tenant_id, session_key) — partial unique index.
        // Postgres: predikat boolean telanjang; SQLite (driver test): '= 1'
        // (boolean disimpan sebagai integer). Pola partial-unique-index yang
        // sudah dipakai di codebase ini (customer_contacts, bandwidth_profiles).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX whatsapp_notification_groups_one_default ON whatsapp_notification_groups (tenant_id, session_key) WHERE is_default');
        } else {
            DB::statement('CREATE UNIQUE INDEX whatsapp_notification_groups_one_default ON whatsapp_notification_groups (tenant_id, session_key) WHERE is_default = 1');
        }

        Schema::create('whatsapp_group_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            // Hapus grup -> routing jatuh ke null -> resolver pakai default.
            $table->foreignId('group_id')->nullable()
                ->constrained('whatsapp_notification_groups')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_routes');
        Schema::dropIfExists('whatsapp_notification_groups');
    }
};
