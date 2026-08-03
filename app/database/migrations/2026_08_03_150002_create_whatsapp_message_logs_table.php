<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Denormalized from customer.reseller_id at queue time — cheap
            // reseller-scoped queue queries without joining through
            // customers every time, same rationale as invoices.reseller_id.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('phone_number');
            $table->string('event_type');
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_message_templates')->nullOnDelete();
            $table->text('rendered_content');
            $table->string('status')->default('queued');
            $table->text('failed_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('reseller_id');
            $table->index('status');
            $table->index('event_type');
            $table->index('created_at');
            // Dedup guards for the scheduled reminder commands (invoice_due_
            // reminder per invoice per day, customer_suspended_reminder per
            // customer per day) — see whatsapp:send-due-reminders /
            // whatsapp:send-suspended-reminders, both query this instead of
            // a separate "already sent today" table.
            $table->index(['invoice_id', 'event_type', 'created_at']);
            $table->index(['customer_id', 'event_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
