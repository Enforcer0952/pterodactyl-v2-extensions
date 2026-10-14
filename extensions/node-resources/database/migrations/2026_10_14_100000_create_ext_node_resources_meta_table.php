<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_node_resources_meta')) {
            return;
        }

        // Small facts about the collector, such as when it last ran.
        Schema::create('ext_node_resources_meta', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->text('value')->nullable();
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_node_resources_meta');
    }
};
