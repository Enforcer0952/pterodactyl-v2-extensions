<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_mc_content_installs')) {
            return;
        }

        Schema::create('ext_mc_content_installs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('kind', 16);
            $table->string('filename', 255);
            $table->string('project_id', 32);
            $table->string('version_id', 32);
            $table->string('title', 255);
            $table->string('version_number', 128);
            $table->string('icon_url', 512)->nullable();
            $table->string('sha1', 40)->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'kind', 'filename']);
            $table->index(['server_id', 'kind', 'project_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_mc_content_installs');
    }
};
