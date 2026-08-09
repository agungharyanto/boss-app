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
        Schema::create('cpe_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Null = pelanggan direct ISP A langsung, sama pola dengan
            // odps/whatsapp_sessions.reseller_id (v0.4.0/v0.5.0).
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            // Jejak asal-usul binding — device mana dari work order mana
            // yang menghasilkan baris ini. Nullable karena binding manual/
            // jalur lain (di luar scope v0.7.1) tetap mungkin di masa depan.
            $table->foreignId('work_order_device_id')->nullable()->constrained('work_order_devices')->nullOnDelete();
            // "OUI-ProductClass-SerialNumber" milik GenieACS sendiri.
            // Nullable karena device bisa saja di-bind sebelum pernah
            // inform ke GenieACS sama sekali (status pending_first_connect).
            $table->string('genieacs_device_id')->nullable()->unique();
            $table->string('manufacturer')->nullable();
            $table->string('model_name')->nullable();
            // Dipakai untuk reconcile device pending_first_connect begitu
            // muncul di GenieACS — lihat CpeBindingService.
            $table->string('serial_number');
            // TR-098 (InternetGatewayDevice) vs TR-181 (Device) — root path
            // data model beda tergantung generasi device, disimpan supaya
            // query parameter berikutnya tidak salah tebak root mana yang
            // dipakai device ini.
            $table->string('tr069_root')->nullable();
            $table->string('status')->default('pending_first_connect');
            $table->timestamp('last_inform_at')->nullable();
            // Kapan proses binding terjadi — dipakai v0.7.4 nanti sebagai
            // gerbang provisioning otomatis, tidak dipakai langsung di v0.7.1.
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('serial_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_devices');
    }
};
