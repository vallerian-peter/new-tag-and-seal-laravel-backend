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
        Schema::table('milkings', function (Blueprint $table) {
            if (!Schema::hasColumn('milkings', 'farmUuid')) {
                $table->string('farmUuid')->after('uuid');
                $table->index('farmUuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('milkings', function (Blueprint $table) {
            if (Schema::hasColumn('milkings', 'farmUuid')) {
                $table->dropIndex(['farmUuid']);
                $table->dropColumn('farmUuid');
            }
        });
    }
};

