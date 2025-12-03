<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Increases UUID column length from 36 chars (standard UUID) to 100 chars
     * to accommodate custom UUID formats like: timestamp-randomNumber-timestamp
     */
    public function up(): void
    {
        $tables = [
            'birth_events',
            'aborted_pregnancies',
            'feedings',
            'dewormings',
            'weight_changes',
            'medications',
            'vaccinations',
            'disposals',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    // Change uuid column from uuid type (36 chars) to string(100)
                    $table->string('uuid', 100)->change();
                });
                
                \Log::info("✅ Updated uuid column length in {$tableName} table");
            } else {
                \Log::warning("⚠️ Table {$tableName} does not exist, skipping...");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'birth_events',
            'aborted_pregnancies',
            'feedings',
            'dewormings',
            'weight_changes',
            'medications',
            'vaccinations',
            'disposals',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    // Revert to standard uuid type (36 chars)
                    // WARNING: This will fail if any UUIDs are longer than 36 chars
                    $table->uuid('uuid')->change();
                });
            }
        }
    }
};

