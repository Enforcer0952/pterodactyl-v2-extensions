<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_ark_tools_config_applies')) {
            return;
        }

        // Settings saved while the server was running, waiting for it to stop.
        Schema::create('ext_ark_tools_config_applies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->longText('payload');
            $table->boolean('start_after')->default(true);
            $table->string('status', 16)->default('waiting');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_ark_tools_config_applies');
    }
};
