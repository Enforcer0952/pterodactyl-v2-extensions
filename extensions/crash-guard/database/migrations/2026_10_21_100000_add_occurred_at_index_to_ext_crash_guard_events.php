<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ext_crash_guard_events') || Schema::hasIndex('ext_crash_guard_events', 'ext_crash_guard_events_occurred_at_index')) {
            return;
        }

        // Pruning deletes by age across all servers.
        Schema::table('ext_crash_guard_events', function (Blueprint $table) {
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ext_crash_guard_events') && Schema::hasIndex('ext_crash_guard_events', 'ext_crash_guard_events_occurred_at_index')) {
            Schema::table('ext_crash_guard_events', function (Blueprint $table) {
                $table->dropIndex(['occurred_at']);
            });
        }
    }
};
