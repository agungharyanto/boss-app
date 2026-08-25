<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.1 — rename Agent -> Referrer (table + FK columns), data preserved
 * via ALTER TABLE/COLUMN RENAME, never drop+recreate. Deliberately NOT
 * "Sales" — App\Enums\AgentType::Sales, App\Enums\RegistrationChannel::Sales,
 * and the Spatie roles sales_internal/sales_freelance already existed and
 * stay untouched, so naming the model "Sales" would have collided with all
 * three. See CLAUDE.md's own v0.9.1 section for the full investigation.
 *
 * FK constraint/index names (agents_tenant_id_foreign, etc.) are
 * deliberately left as-is — cosmetic only, functionally irrelevant, per
 * explicit instruction not to rename them in this same migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('agents', 'referrers');

        Schema::table('commission_ledger', function ($table) {
            $table->renameColumn('agent_id', 'referrer_id');
        });

        Schema::table('customers', function ($table) {
            $table->renameColumn('referred_by_agent_id', 'referred_by_referrer_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function ($table) {
            $table->renameColumn('referred_by_referrer_id', 'referred_by_agent_id');
        });

        Schema::table('commission_ledger', function ($table) {
            $table->renameColumn('referrer_id', 'agent_id');
        });

        Schema::rename('referrers', 'agents');
    }
};
