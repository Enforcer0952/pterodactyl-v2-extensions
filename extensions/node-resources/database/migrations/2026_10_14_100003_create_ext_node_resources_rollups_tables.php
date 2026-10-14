<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Averages and peaks per node: 5-minute rows (resolution 300) and hourly rows (3600).
        if (! Schema::hasTable('ext_node_resources_node_rollups')) {
            Schema::create('ext_node_resources_node_rollups', function (Blueprint $table) {
                $table->unsignedInteger('node_id');
                $table->unsignedSmallInteger('resolution');
                $table->unsignedInteger('bucket');
                $table->unsignedSmallInteger('samples');
                foreach (['mem_used', 'disk_used', 'load1', 'sv_mem', 'sv_disk', 'sv_cpu'] as $metric) {
                    $table->double($metric.'_avg')->nullable();
                    $table->double($metric.'_max')->nullable();
                }
                $table->unsignedBigInteger('mem_total')->nullable();
                $table->unsignedBigInteger('disk_total')->nullable();
                $table->unsignedSmallInteger('cpus')->nullable();

                $table->primary(['node_id', 'resolution', 'bucket']);
                $table->index(['resolution', 'bucket']);
                $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('ext_node_resources_server_rollups')) {
            Schema::create('ext_node_resources_server_rollups', function (Blueprint $table) {
                $table->unsignedInteger('server_id');
                $table->unsignedSmallInteger('resolution');
                $table->unsignedInteger('bucket');
                $table->unsignedSmallInteger('samples');
                foreach (['mem', 'cpu', 'disk'] as $metric) {
                    $table->double($metric.'_avg')->nullable();
                    $table->double($metric.'_max')->nullable();
                }
                $table->unsignedBigInteger('net_rx')->nullable();
                $table->unsignedBigInteger('net_tx')->nullable();

                $table->primary(['server_id', 'resolution', 'bucket']);
                $table->index(['resolution', 'bucket']);
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_node_resources_server_rollups');
        Schema::dropIfExists('ext_node_resources_node_rollups');
    }
};
