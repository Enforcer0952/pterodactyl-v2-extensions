<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_node_resources_probes')) {
            return;
        }

        // The probe server of each node.
        Schema::create('ext_node_resources_probes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('node_id')->unique();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('created_by')->nullable();
            // Time of the newest probe line seen, as the node printed it, and when the panel saw it.
            $table->unsignedInteger('last_ts')->nullable();
            $table->unsignedInteger('last_line_at')->nullable();
            // The last time the collector started a stopped probe.
            $table->unsignedInteger('last_start_at')->nullable();
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_node_resources_probes');
    }
};
