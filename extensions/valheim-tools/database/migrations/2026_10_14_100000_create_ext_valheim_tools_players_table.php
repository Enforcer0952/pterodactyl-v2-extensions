<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_valheim_tools_players')) {
            return;
        }

        // Players seen on the console: names for the IDs on the access lists, and first and last seen.
        Schema::create('ext_valheim_tools_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // A Steam ID or platform ID, or "~name" for a player whose ID was not in the console.
            $table->string('player_id', 64);
            $table->string('name', 128);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['server_id', 'player_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_valheim_tools_players');
    }
};
