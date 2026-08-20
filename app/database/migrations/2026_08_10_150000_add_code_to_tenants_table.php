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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('slug');
        });

        // Backfill existing tenants (harmless no-op on a fresh install —
        // there are no tenant rows yet at migration time there). Uses the
        // same NameToCodeDeriver::deriveUnique() the Tenant model's own
        // creating() hook uses for every tenant from here on, so a
        // backfilled row and a freshly created one get codes the same way.
        DB::table('tenants')->whereNull('code')->orderBy('id')->get(['id', 'name'])->each(function ($tenant) {
            $code = NameToCodeDeriver::deriveUnique(
                $tenant->name,
                fn (string $candidate) => DB::table('tenants')->where('code', $candidate)->exists()
            );

            if ($code !== null) {
                DB::table('tenants')->where('id', $tenant->id)->update(['code' => $code]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
