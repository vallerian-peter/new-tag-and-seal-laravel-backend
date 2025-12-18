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
        Schema::table('bills', function (Blueprint $table) {
            // Drop existing unique constraint
            $table->dropUnique('bills_billno_unique');
        });

        Schema::table('bills', function (Blueprint $table) {
            // Increase billNo column length from 7 to 30 to accommodate BILL-YYYYMMDDHHMMSS-XXX format
            $table->string('billNo', 30)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique('bills_billno_unique');
        });

        Schema::table('bills', function (Blueprint $table) {
            // Revert billNo column length back to 7
            $table->string('billNo', 7)->unique()->change();
        });
    }
};
