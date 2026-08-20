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
        Schema::create('cpe_device_model_capabilities', function (Blueprint $table) {
            $table->id();
            // Same key shape/posture as cpe_parameter_maps — a hardware
            // fact about the device model (which SSID slots this OUI+
            // ProductClass combo actually has), platform-level, not
            // tenant/reseller data.
            $table->string('oui');
            $table->string('product_class');
            // How many WLANConfiguration slots to render on the CPE detail
            // page's WiFi/SSID table (1..max_ssid_slots), filling any index
            // with no real data as an empty/"Nonaktif" placeholder row
            // rather than hiding it — see CpeDeviceModelCapabilitySeeder's
            // own docblock for why this is the observed MAX index, not
            // necessarily a claim every index in between structurally
            // exists on the hardware (some device families show real gaps,
            // e.g. indices 1 and 5 only, nothing in between).
            $table->unsignedTinyInteger('max_ssid_slots')->default(4);
            $table->boolean('supports_5g')->default(false);
            // Null until a real device of this exact OUI+ProductClass has
            // actually shown data at max_ssid_slots (or a higher index than
            // was previously on record) — same "don't claim confidence we
            // don't have" posture as cpe_parameter_maps.verified_at.
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_against_device_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['oui', 'product_class']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_device_model_capabilities');
    }
};
