<?php

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
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number');
            $table->string('relationship')->nullable();
            $table->string('access_level')->default('view_only');
            $table->boolean('can_view_billing')->default(false);
            $table->boolean('can_request_service_change')->default(false);
            $table->boolean('can_receive_notifications')->default(true);
            $table->boolean('is_authorized_contact')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // Hanya satu kontak per pelanggan yang boleh ditandai authorized (partial unique index,
        // didukung baik di PostgreSQL maupun SQLite yang dipakai phpunit).
        DB::statement(
            'CREATE UNIQUE INDEX customer_contacts_one_authorized_per_customer '.
            'ON customer_contacts (customer_id) WHERE is_authorized_contact = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
