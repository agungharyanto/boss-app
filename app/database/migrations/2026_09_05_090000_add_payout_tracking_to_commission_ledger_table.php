<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.11 — bagian "Payment" dari scope Commission (v0.9.0) yang belum
 * pernah dibangun. `App\Enums\CommissionStatus` sudah punya case `Paid`
 * sejak v0.3.0, tapi tidak pernah dipakai kode manapun — tidak ada satu
 * baris pun yang pernah mentransisikan status ke situ. Bukan tabel
 * terpisah `commission_payouts` (konsep itu, dicek lewat grep menyeluruh
 * ke migrations/models/CLAUDE.md/ROADMAP.md/CHANGELOG.md, TIDAK PERNAH
 * direalisasikan sama sekali) — mengikuti pola yang SAMA dengan tracking
 * setoran Titip (`deposit_status`/`deposited_at`/`deposited_by`): perluas
 * `commission_ledger` itu sendiri, bukan tabel baru.
 *
 * - `paid_at`             — kapan status ditransisikan ke Paid.
 * - `paid_by`             — admin yang menandainya (nullOnDelete, sama
 *   posture `deposited_by`).
 * - `payment_proof_path`  — path file bukti bayar (foto transfer/cash) di
 *   disk 'local' (privat, sama posture `work_order_photos`/
 *   `fiber_node_photos` — tidak pernah publicly served, hanya lewat
 *   controller ber-auth). Nullable — baris komisi bulanan (batch, v0.9.11
 *   Langkah 3) tidak mensyaratkan upload bukti, hanya Titip (instan,
 *   Langkah 2) yang mewajibkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('deposited_by');
            $table->foreignId('paid_by')->nullable()->after('paid_at')
                ->constrained('users')->nullOnDelete();
            $table->string('payment_proof_path')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['paid_at', 'payment_proof_path']);
        });
    }
};
