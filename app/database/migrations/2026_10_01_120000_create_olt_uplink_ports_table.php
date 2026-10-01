<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.5 Bagian 2 — cache status port uplink OLT (tab "Uplink" gaya
 * SmartOLT), ON-DEMAND (pola sama onu_registries): baris hanya dibuat/
 * diperbarui lewat OltUplinkService::refresh() saat tab dibuka / tombol
 * "Refresh" ditekan — BUKAN job berkala, BUKAN sumber kebenaran tulis.
 * Semua kolom hasil parse READ-ONLY dari OLT (show interface / optical-
 * module-info / vlan port). Tidak ada kredensial di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_uplink_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_device_id')->constrained('olt_devices')->cascadeOnDelete();

            $table->string('port_name');            // gei_1/19/4 / xgei_1/19/1 (3-segmen)
            $table->boolean('is_10g')->default(false);

            // show interface <port>
            $table->string('admin_state', 16)->nullable();   // up / down
            $table->string('oper_status', 16)->nullable();    // up / down
            $table->string('line_protocol', 16)->nullable();
            $table->string('description')->nullable();
            $table->string('negotiation', 16)->nullable();     // enable / disable
            $table->string('port_type', 16)->nullable();        // optical / copper
            $table->string('duplex', 16)->nullable();
            $table->unsignedInteger('mtu')->nullable();

            // show interface optical-module-info <port>
            $table->unsignedInteger('wavelength_nm')->nullable();
            $table->decimal('rx_power_dbm', 8, 3)->nullable();
            $table->decimal('tx_power_dbm', 8, 3)->nullable();
            $table->decimal('temperature_c', 6, 2)->nullable();
            $table->string('module_type')->nullable();

            // show vlan port <port>
            $table->string('port_mode', 16)->nullable();        // hybrid / access / trunk
            $table->unsignedSmallInteger('pvid')->nullable();
            $table->string('untagged_vlans')->nullable();       // "1"
            $table->string('tagged_vlans', 1024)->nullable();   // "9-10,69,101,..."

            // Raw per command (audit/debug) — tidak jadi sumber kebenaran tulis.
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['olt_device_id', 'port_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_uplink_ports');
    }
};
