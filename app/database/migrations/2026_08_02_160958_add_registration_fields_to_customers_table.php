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

            $table->string('nik')->nullable()->after('registration_channel');
            $table->decimal('latitude', 10, 7)->nullable()->after('nik');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('package')->nullable()->after('longitude');

            $table->index('referred_by_agent_id');
            // Unique per tenant, not globally — two unrelated ISP tenants may
            // legitimately end up with an overlapping NIK record.
            $table->unique(['tenant_id', 'nik']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'nik']);
            $table->dropForeign(['referred_by_agent_id']);
            $table->dropColumn([
                'referred_by_agent_id',
                'registration_status',
                'registration_channel',
                'nik',
                'latitude',
                'longitude',
                'package',
            ]);
        });
    }
};
