<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per node per minute. Kept for 48 hours.
        if (! Schema::hasTable('ext_node_resources_node_samples')) {
            Schema::create('ext_node_resources_node_samples', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('node_id');
                $table->unsignedInteger('taken_at');
                $table->boolean('reachable')->default(true);
                // Host numbers, from Wings and the probe. Null when unknown.
                $table->unsignedBigInteger('mem_total')->nullable();
                $table->unsignedBigInteger('mem_used')->nullable();
                $table->unsignedBigInteger('disk_total')->nullable();
                $table->unsignedBigInteger('disk_used')->nullable();
                $table->double('load1')->nullable();
                $table->unsignedSmallInteger('cpus')->nullable();
                // The same minute added up over the node's servers.
                $table->unsignedBigInteger('sv_mem')->nullable();
                $table->unsignedBigInteger('sv_disk')->nullable();
                $table->double('sv_cpu')->nullable();
                $table->unsignedSmallInteger('sv_count')->default(0);

                $table->index(['node_id', 'taken_at']);
                $table->index('taken_at');
                $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            });
        }

        // One row per server per minute. Kept for 48 hours.
        if (! Schema::hasTable('ext_node_resources_server_samples')) {
            Schema::create('ext_node_resources_server_samples', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('server_id');
                $table->unsignedInteger('node_id');
                $table->unsignedInteger('taken_at');
                $table->string('state', 16)->nullable();
                $table->unsignedBigInteger('mem');
                $table->double('cpu');
                $table->unsignedBigInteger('disk');
                $table->unsignedBigInteger('net_rx')->default(0);
                $table->unsignedBigInteger('net_tx')->default(0);

                $table->index(['server_id', 'taken_at']);
                $table->index(['node_id', 'taken_at']);
                $table->index('taken_at');
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_node_resources_server_samples');
        Schema::dropIfExists('ext_node_resources_node_samples');
    }
};
