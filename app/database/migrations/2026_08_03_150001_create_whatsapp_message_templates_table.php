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
        Schema::create('whatsapp_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = default ISP-level template for this event_type, used as
            // the fallback whenever a reseller has no active override — see
            // App\Services\WhatsappTemplateService::resolve().
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('event_type');
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One default per (tenant, event_type), and one override per
        // (tenant, reseller, event_type) — same NULL-distinctness reasoning
        // as whatsapp_sessions above.
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_templates_reseller_unique ON whatsapp_message_templates (tenant_id, reseller_id, event_type) WHERE reseller_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_templates_default_unique ON whatsapp_message_templates (tenant_id, event_type) WHERE reseller_id IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_templates');
    }
};
