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
        Schema::create('cpe_binding_rejections', function (Blueprint $table) {
            $table->id();
            // Same tenant convention as every other tenant-scoped table —
            // derived from the customer at rejection time, not from Auth
            // directly (App\Services\Network\LegacyDeviceMatcherService
            // reads this with no authenticated user).
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Plain string, not a foreign key into cpe_devices — the whole
            // point is to remember a rejected (device, customer) PAIR even
            // after the wrongly-bound cpe_devices row itself is deleted (see
            // App\Livewire\Network\CpeDeviceIndex::unbindDevice()).
            $table->string('genieacs_device_id');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('rejected_at');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['genieacs_device_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_binding_rejections');
    }
};
