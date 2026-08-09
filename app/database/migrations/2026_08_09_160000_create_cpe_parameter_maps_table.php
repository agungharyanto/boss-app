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
        Schema::create('cpe_parameter_maps', function (Blueprint $table) {
            $table->id();
            // Matched against _deviceId._OUI / _deviceId._ProductClass from
            // GenieACS (App\Services\Network\GenieAcsClientService) — a
            // hardware fact about the device model, not tenant/reseller
            // data, so this table is platform-level (no tenant_id), same
            // posture as payment_gateway_channels.
            $table->string('oui');
            $table->string('product_class');
            // Stable logical name (e.g. "rx_power_dbm", "tx_power_dbm") —
            // what CpeParameterResolverService's callers actually ask for,
            // decoupled from the vendor's own parameter path so the same
            // logical key can map to a completely different path per
            // vendor/model.
            $table->string('parameter_key');
            $table->string('parameter_path');
            $table->string('value_type')->nullable();
            $table->string('conversion_formula');
            // Formula-specific coefficients (e.g. {"scale":0.0001} for the
            // SFF-8472 optical log10 formula, {"multiplier":...,"offset":...}
            // for a plain linear one) — see
            // App\Enums\CpeParameterConversionFormula /
            // App\Services\Network\ParameterConversionService.
            $table->json('conversion_params')->nullable();
            // Null until a real device's raw value has actually been
            // cross-checked against a plausible real-world reading (see
            // CLAUDE.md "GenieACS Vendor Parameter Mapping (v0.7.2)") — an
            // unverified row is a guess from vendor docs/community research,
            // not confirmed against real hardware, and callers/UI should be
            // able to tell the difference.
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_against_device_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['oui', 'product_class', 'parameter_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_parameter_maps');
    }
};
