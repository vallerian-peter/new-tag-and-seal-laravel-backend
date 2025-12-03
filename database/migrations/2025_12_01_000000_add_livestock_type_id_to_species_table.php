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
        Schema::table('species', function (Blueprint $table) {
            // Link each species to an optional livestock type
            $table->unsignedBigInteger('livestockTypeId')->nullable()->after('name');

            $table->foreign('livestockTypeId')
                ->references('id')
                ->on('livestock_types')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropForeign(['livestockTypeId']);
            $table->dropColumn('livestockTypeId');
        });
    }
};


