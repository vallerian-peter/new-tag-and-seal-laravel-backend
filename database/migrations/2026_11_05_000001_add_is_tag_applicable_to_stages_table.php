<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('stages') && !Schema::hasColumn('stages', 'is_tag_applicable')) {
            Schema::table('stages', function (Blueprint $table) {
                $table->boolean('is_tag_applicable')->default(true)->after('livestockTypeId');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('stages') && Schema::hasColumn('stages', 'is_tag_applicable')) {
            Schema::table('stages', function (Blueprint $table) {
                $table->dropColumn('is_tag_applicable');
            });
        }
    }
};

