<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_ark_tools_players')) {
            return;
        }

        // Players seen in ListPlayers, so Steam ids on the ban list and whitelist get names.
        Schema::create('ext_ark_tools_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('steam_id', 20);
            $table->string('name', 128);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['server_id', 'steam_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_ark_tools_players');
    }
};
