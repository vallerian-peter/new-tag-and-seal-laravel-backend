<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if columns need to be renamed
        $columns = DB::select("SHOW COLUMNS FROM birth_events");
        $hasCalvingTypeId = false;
        $hasBirthTypeId = false;
        $hasCalvingProblemsId = false;
        $hasBirthProblemsId = false;

        foreach ($columns as $col) {
            if ($col->Field === 'calvingTypeId') $hasCalvingTypeId = true;
            if ($col->Field === 'birthTypeId') $hasBirthTypeId = true;
            if ($col->Field === 'calvingProblemsId') $hasCalvingProblemsId = true;
            if ($col->Field === 'birthProblemsId') $hasBirthProblemsId = true;
        }

        if ($hasCalvingTypeId && !$hasBirthTypeId) {
            // Columns still have old names, rename them
            // Step 1: Get and drop old foreign keys by constraint name
            $fkNames = DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = 'birth_events'
                AND TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME = 'calvingTypeId' OR COLUMN_NAME = 'calvingProblemsId')
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            foreach ($fkNames as $fk) {
                try {
                    DB::statement("ALTER TABLE birth_events DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Exception $e) {
                    // Ignore if doesn't exist
                }
            }

            // Step 2: Rename columns to generic names using raw SQL
            DB::statement('ALTER TABLE birth_events CHANGE COLUMN calvingTypeId birthTypeId BIGINT UNSIGNED NOT NULL');
            if ($hasCalvingProblemsId) {
                DB::statement('ALTER TABLE birth_events CHANGE COLUMN calvingProblemsId birthProblemsId BIGINT UNSIGNED NULL');
            }

            // Step 3: Add new foreign keys pointing to renamed tables (birth_types, birth_problems)
            Schema::table('birth_events', function (Blueprint $table) {
                $table->foreign('birthTypeId')->references('id')->on('birth_types')->onDelete('cascade');
                $table->foreign('birthProblemsId')->references('id')->on('birth_problems')->onDelete('cascade');
            });
        }
        // If columns already renamed, do nothing
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if columns need to be reverted
        $columns = DB::select("SHOW COLUMNS FROM birth_events");
        $hasBirthTypeId = false;
        $hasCalvingTypeId = false;

        foreach ($columns as $col) {
            if ($col->Field === 'birthTypeId') $hasBirthTypeId = true;
            if ($col->Field === 'calvingTypeId') $hasCalvingTypeId = true;
        }

        if ($hasBirthTypeId && !$hasCalvingTypeId) {
            // Step 1: Drop foreign keys
            $fkNames = DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = 'birth_events'
                AND TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME = 'birthTypeId' OR COLUMN_NAME = 'birthProblemsId')
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            foreach ($fkNames as $fk) {
                try {
                    DB::statement("ALTER TABLE birth_events DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // Step 2: Rename columns back
            DB::statement('ALTER TABLE birth_events CHANGE COLUMN birthTypeId calvingTypeId BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE birth_events CHANGE COLUMN birthProblemsId calvingProblemsId BIGINT UNSIGNED NULL');

            // Step 3: Restore foreign key constraints (pointing back to calving_types/calving_problems)
            Schema::table('birth_events', function (Blueprint $table) {
                $table->foreign('calvingTypeId')->references('id')->on('calving_types')->onDelete('cascade');
                $table->foreign('calvingProblemsId')->references('id')->on('calving_problems')->onDelete('cascade');
            });
        }
    }
};
