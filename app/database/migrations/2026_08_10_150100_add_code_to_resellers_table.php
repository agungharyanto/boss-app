<?php

use App\Support\NameToCodeDeriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Separate from invoice_code (v0.3.4, invoice-numbering only) —
            // this is the general-purpose short code CID (customers.cid)
            // derives from. Unique per tenant, not globally, same posture
            // as invoice_code and every other per-tenant-unique column in
            // this codebase.
            $table->string('code')->nullable()->after('slug');
            $table->unique(['tenant_id', 'code']);
        });

        // Backfill existing resellers (no rows exist yet on this server, so
        // this is currently a no-op — kept for the same reproducibility
        // reason as the tenants.code backfill in the companion migration).
        DB::table('resellers')->whereNull('code')->orderBy('id')->get(['id', 'tenant_id', 'name'])->each(function ($reseller) {
            $code = NameToCodeDeriver::deriveUnique(
                $reseller->name,
                fn (string $candidate) => DB::table('resellers')
                    ->where('tenant_id', $reseller->tenant_id)
                    ->where('code', $candidate)
                    ->exists()
            );

            if ($code !== null) {
                DB::table('resellers')->where('id', $reseller->id)->update(['code' => $code]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
