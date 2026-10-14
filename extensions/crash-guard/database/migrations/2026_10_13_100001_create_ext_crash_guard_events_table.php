<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_crash_guard_events')) {
            return;
        }

        // Crashes the guard found, and what it did about them.
        Schema::create('ext_crash_guard_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // crash, trip, release or cleared
            $table->string('kind', 12);
            // A crash waits as "pending" until the activity log can say whether someone caused it.
            $table->string('status', 12)->default('confirmed');
            $table->string('via', 24)->nullable();
            $table->unsignedBigInteger('uptime_before')->nullable();
            $table->string('note', 191)->nullable();
            $table->timestamp('since')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['server_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_crash_guard_events');
    }
};
