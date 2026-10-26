<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.23.8 LANGKAH D (REVISI) — admission mode jadi PER-DEVICE, bukan hardcode
 * per adapter class.
 *
 *  - requires_bind_onu (bool NULLABLE): true  → OLT butuh bind-onu+confirm
 *    eksplisit sebelum ONU online (E04ID, deny-by-default EPON); false → ONU
 *    auto-authorize begitu nyambung (G02ID GPON); NULL → admission mode belum
 *    ditentukan → adapter WAJIB menolak (jangan diam-diam pilih default).
 *  - omci_channel_enabled / tr069_channel_enabled (bool, default true):
 *    saklar kanal provisioning per-OLT. Orkestrator ZTP menolak merutekan WAN
 *    lewat kanal yang dimatikan (mis. OLT yang WAN-nya belum boleh via OMCI).
 *
 * Backfill 3 row riil ditentukan dari vendor key (manufacturer|model), bukan
 * bergantung pada App\Models\OltDevice (migration tetap self-contained):
 *   HSGQ|HSGQ-E04ID → requires_bind_onu = true
 *   HSGQ|HSGQ-G02ID → requires_bind_onu = false
 *   ZTE|C300 (+ lainnya) → NULL  (WAN via OMCI, bukan admission bind-onu)
 * Channel default true untuk semua (default kolom menangani ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->boolean('requires_bind_onu')->nullable()->after('access_protocol');
            $table->boolean('omci_channel_enabled')->default(true)->after('requires_bind_onu');
            $table->boolean('tr069_channel_enabled')->default(true)->after('omci_channel_enabled');
        });

        // Backfill requires_bind_onu dari vendor key (channel pakai default true).
        $rows = DB::table('olt_devices as d')
            ->leftJoin('olt_models as m', 'm.id', '=', 'd.olt_model_id')
            ->leftJoin('olt_manufacturers as mf', 'mf.id', '=', 'm.olt_manufacturer_id')
            ->select('d.id', 'mf.name as mfr', 'm.name as model')
            ->get();

        foreach ($rows as $row) {
            $key = strtoupper(trim((string) $row->mfr)).'|'.strtoupper(trim((string) $row->model));
            $requires = match ($key) {
                'HSGQ|HSGQ-E04ID' => true,
                'HSGQ|HSGQ-G02ID' => false,
                default => null, // ZTE C300 (OMCI) + vendor tak dikenal → belum ditentukan
            };
            DB::table('olt_devices')->where('id', $row->id)->update(['requires_bind_onu' => $requires]);
        }
    }

    public function down(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->dropColumn(['requires_bind_onu', 'omci_channel_enabled', 'tr069_channel_enabled']);
        });
    }
};
