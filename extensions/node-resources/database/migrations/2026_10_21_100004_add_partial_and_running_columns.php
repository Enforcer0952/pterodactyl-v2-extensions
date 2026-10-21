<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sample is partial when not every server on the node was read that minute.
        Schema::table('ext_node_resources_node_samples', function (Blueprint $table) {
            if (! Schema::hasColumn('ext_node_resources_node_samples', 'partial')) {
                $table->boolean('partial')->default(false)->after('reachable');
            }
            if (! Schema::hasColumn('ext_node_resources_node_samples', 'sv_expected')) {
                // How many servers the node had to read. Compare with sv_count.
                $table->unsignedSmallInteger('sv_expected')->nullable()->after('sv_count');
            }
        });

        // Minutes (samples) the server was running in the bucket, for the suggestions.
        Schema::table('ext_node_resources_server_rollups', function (Blueprint $table) {
            if (! Schema::hasColumn('ext_node_resources_server_rollups', 'running')) {
                $table->unsignedSmallInteger('running')->default(0)->after('samples');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ext_node_resources_server_rollups', function (Blueprint $table) {
            if (Schema::hasColumn('ext_node_resources_server_rollups', 'running')) {
                $table->dropColumn('running');
            }
        });
        Schema::table('ext_node_resources_node_samples', function (Blueprint $table) {
            foreach (['sv_expected', 'partial'] as $column) {
                if (Schema::hasColumn('ext_node_resources_node_samples', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
