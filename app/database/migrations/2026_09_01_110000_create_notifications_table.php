<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Standard
 * Laravel notifications table (same shape `php artisan notifications:table`
 * publishes) — confirmed via grep before writing this that it genuinely
 * never existed in this codebase, despite `App\Models\User` already using
 * the `Notifiable` trait since the original Laravel scaffold (unused until
 * now). First real consumer: App\Notifications\OdpCapacityExhaustedNotification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
