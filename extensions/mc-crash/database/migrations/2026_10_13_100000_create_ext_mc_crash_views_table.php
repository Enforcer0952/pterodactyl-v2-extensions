<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_mc_crash_views')) {
            return;
        }

        // When a user last opened the Crashes page of a server, so the console badge only shows newer crashes.
        Schema::create('ext_mc_crash_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->unique(['server_id', 'user_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_mc_crash_views');
    }
};
