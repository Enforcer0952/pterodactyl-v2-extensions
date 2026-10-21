<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ext_mc_content_installs') || Schema::hasColumn('ext_mc_content_installs', 'file_size')) {
            return;
        }

        Schema::table('ext_mc_content_installs', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->nullable()->after('sha1');
            $table->unsignedBigInteger('file_mtime')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ext_mc_content_installs') && Schema::hasColumn('ext_mc_content_installs', 'file_size')) {
            Schema::table('ext_mc_content_installs', function (Blueprint $table) {
                $table->dropColumn(['file_size', 'file_mtime']);
            });
        }
    }
};
