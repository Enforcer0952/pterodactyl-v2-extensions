<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_crash_guard_servers')) {
            return;
        }

        // Guard settings per server, and what the guard last saw and did.
        Schema::create('ext_crash_guard_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            // null = follow the default from the extension settings.
            $table->boolean('enabled')->nullable();
            $table->unsignedSmallInteger('max_crashes')->nullable();
            $table->unsignedSmallInteger('window_minutes')->nullable();

            // The last reading of the server.
            $table->string('last_state', 16)->nullable();
            $table->unsignedBigInteger('last_uptime')->nullable();
            $table->timestamp('last_sampled_at')->nullable();

            // Crashes before this moment no longer count.
            $table->timestamp('cleared_at')->nullable();

            // Set while the guard is holding the server off.
            $table->timestamp('tripped_at')->nullable();
            $table->unsignedSmallInteger('trip_crashes')->nullable();
            $table->unsignedSmallInteger('trip_minutes')->nullable();
            $table->timestamp('stop_sent_at')->nullable();
            $table->timestamp('up_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_crash_guard_servers');
    }
};
