<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.13.4.1 — modem WAJIB dicatat PER-UNIT (serial_number + mac_address),
 * bukan cuma jumlah seperti alat lain — benih minimal untuk modul Gudang
 * (backlog terpisah), TAPI murni catatan per-WO di sini, TIDAK ADA
 * stok/deduction/cek-ke-inventori-pusat apa pun (belum ada Gudang sama
 * sekali). Data ini akan DIPAKAI ULANG di v0.13.4.2 (Aktivasi) — teknisi
 * nanti pilih dari daftar yang DIA catat sendiri di sini, bukan dicek ke
 * mana pun.
 *
 * `technician_id` nullable + nullOnDelete() — sama alasan
 * work_order_tool_usages (preservasi histori, bukan penanda relasi murni).
 * SENGAJA nol unique constraint pada serial_number/mac_address — tidak ada
 * inventori pusat yang dicek, salah catat/dicatat ulang di WO lain bukan
 * pelanggaran skema (dikonfirmasi eksplisit di kickoff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_modem_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->nullOnDelete();
            $table->string('serial_number');
            $table->string('mac_address');
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_modem_units');
    }
};
