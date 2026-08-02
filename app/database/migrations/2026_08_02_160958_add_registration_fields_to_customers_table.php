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
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('referred_by_agent_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('agents')
                ->nullOnDelete();

            $table->string('registration_status')->default('registered')->after('status');
            $table->string('registration_channel')->nullable()->after('registration_status');

            $table->index('referred_by_agent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['referred_by_agent_id']);
            $table->dropColumn(['referred_by_agent_id', 'registration_status', 'registration_channel']);
        });
    }
};
