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
        // This is BOSS App's own business inventory of Mikrotik routers
        // (boss_db) — NOT FreeRADIUS's own internal `nas` table (radius_db,
        // standard FreeRADIUS schema, the RADIUS-client whitelist). Same
        // table name, different database, different purpose — see
        // CLAUDE.md "FreeRADIUS Core & NAS Management (v0.6.1)".
        Schema::create('nas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null = milik ISP A langsung — pola sama seperti
            // whatsapp_sessions/odps/technicians/work_orders.
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Nullable sengaja — baru terisi otomatis setelah VPN
            // provisioning (v0.6.2). JANGAN dibuat required di v0.6.1.
            $table->string('mikrotik_ip')->nullable();
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('api_username')->nullable();
            $table->text('api_password')->nullable(); // cast 'encrypted'

            $table->text('radius_secret'); // cast 'encrypted'

            // Port RADIUS unik per-NAS (keputusan terkunci cluster v0.6.x —
            // lihat docs/ROADMAP.md). auth_port/acct_port nullable sampai
            // dynamic virtual server + port allocator (v0.6.5) mengisi
            // otomatis; coa_port satu-satunya dengan default eksplisit
            // (3799 = standar RFC 5176 Change-of-Authorization/Disconnect).
            $table->unsignedInteger('auth_port')->nullable();
            $table->unsignedInteger('acct_port')->nullable();
            $table->unsignedInteger('coa_port')->default(3799);

            $table->string('status')->default('unknown');
            $table->timestamp('last_ping_at')->nullable();
            $table->string('timezone')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'reseller_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nas');
    }
};
