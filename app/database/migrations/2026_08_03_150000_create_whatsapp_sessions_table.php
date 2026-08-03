<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = the single "direct" session for customers with no
            // reseller (session_key literal "direct"), same pattern as the
            // direct row in reseller_tax_ledger / komdigi_remittance_summary.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('phone_number')->nullable();
            $table->string('status')->default('qr_pending');
            $table->text('qr_code_data')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_disconnected_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // One session per reseller, and at most one "direct" (reseller_id
        // null) session per tenant. A plain unique(['tenant_id',
        // 'reseller_id']) would NOT enforce the second half (Postgres/SQLite
        // both treat NULL as distinct in a normal unique index, letting an
        // unlimited number of NULL rows through) — hence two separate
        // partial unique indexes instead of one composite constraint.
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_sessions_reseller_unique ON whatsapp_sessions (tenant_id, reseller_id) WHERE reseller_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_sessions_direct_unique ON whatsapp_sessions (tenant_id) WHERE reseller_id IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
