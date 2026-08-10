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
        Schema::create('cpe_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cpe_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Denormalized from cpe_devices.reseller_id at write time, same
            // rationale as whatsapp_message_logs.reseller_id — cheap
            // reseller-scoped history queries without joining cpe_devices.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users');
            $table->string('action_type');
            // Never the plaintext new/old password — App\Services\Network\
            // CpeActionService redacts it to a sha256 fingerprint before
            // this is written (see its own docblock for why sha256, not
            // bcrypt: this is an audit fingerprint for "did this change to
            // the same value as X", not a retrievable credential store —
            // the real credential lives only on the device/GenieACS).
            $table->json('parameters')->nullable();
            $table->string('genieacs_task_id')->nullable();
            // "delivered" = task successfully enqueued on genieacs-nbi, NOT
            // confirmation the device executed it — see
            // App\Enums\CpeActionStatus's own docblock.
            $table->string('status')->default('queued');
            $table->text('failed_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('reseller_id');
            $table->index(['cpe_device_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_action_logs');
    }
};
