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
        Schema::table('birth_types', function (Blueprint $table) {
            $table->foreignId('livestockTypeId')->nullable()->after('name')->constrained('livestock_types')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('birth_types', function (Blueprint $table) {
            $table->dropForeign(['livestockTypeId']);
            $table->dropColumn('livestockTypeId');
        });
    }
};

