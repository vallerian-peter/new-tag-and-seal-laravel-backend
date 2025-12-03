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
        // Check if tables need to be renamed
        if (Schema::hasTable('calving_types') && !Schema::hasTable('birth_types')) {
            // Step 1: Get actual foreign key constraint names for birth_events
            $fkNames = DB::select("
                SELECT CONSTRAINT_NAME, COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'birth_events' 
                AND TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME = 'calvingTypeId' OR COLUMN_NAME = 'calvingProblemsId' OR COLUMN_NAME = 'birthTypeId' OR COLUMN_NAME = 'birthProblemsId')
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            // Step 2: Drop old foreign keys by constraint name
            foreach ($fkNames as $fk) {
                try {
                    DB::statement("ALTER TABLE birth_events DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Exception $e) {
                    // Ignore if doesn't exist
                }
            }
            
            // Step 3: Rename calving_types to birth_types
            Schema::rename('calving_types', 'birth_types');
            
            // Step 4: Rename calving_problems to birth_problems
            Schema::rename('calving_problems', 'birth_problems');
            
            // Step 5: Re-add foreign keys pointing to renamed tables
            // Check which column names exist
            $columns = DB::select("SHOW COLUMNS FROM birth_events");
            $hasCalvingTypeId = false;
            $hasBirthTypeId = false;
            foreach ($columns as $col) {
                if ($col->Field === 'calvingTypeId') $hasCalvingTypeId = true;
                if ($col->Field === 'birthTypeId') $hasBirthTypeId = true;
            }
            
            Schema::table('birth_events', function (Blueprint $table) use ($hasCalvingTypeId, $hasBirthTypeId) {
                if ($hasCalvingTypeId) {
                    $table->foreign('calvingTypeId')->references('id')->on('birth_types')->onDelete('cascade');
                    $table->foreign('calvingProblemsId')->references('id')->on('birth_problems')->onDelete('cascade');
                } elseif ($hasBirthTypeId) {
                    $table->foreign('birthTypeId')->references('id')->on('birth_types')->onDelete('cascade');
                    $table->foreign('birthProblemsId')->references('id')->on('birth_problems')->onDelete('cascade');
                }
            });
        }
        // If tables already renamed, migration is already done - just ensure foreign keys are correct
        elseif (Schema::hasTable('birth_types') && Schema::hasTable('birth_problems')) {
            // Tables already renamed, just ensure foreign keys point to correct tables
            $fkNames = DB::select("
                SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'birth_events' 
                AND TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME = 'birthTypeId' OR COLUMN_NAME = 'birthProblemsId')
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            // Check if any foreign keys point to wrong tables
            $needsUpdate = false;
            foreach ($fkNames as $fk) {
                if ($fk->REFERENCED_TABLE_NAME !== 'birth_types' && $fk->REFERENCED_TABLE_NAME !== 'birth_problems') {
                    $needsUpdate = true;
                    break;
                }
            }
            
            if ($needsUpdate) {
                // Drop and recreate foreign keys
                foreach ($fkNames as $fk) {
                    try {
                        DB::statement("ALTER TABLE birth_events DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
                
                Schema::table('birth_events', function (Blueprint $table) {
                    $table->foreign('birthTypeId')->references('id')->on('birth_types')->onDelete('cascade');
                    $table->foreign('birthProblemsId')->references('id')->on('birth_problems')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('birth_types') && Schema::hasTable('birth_problems')) {
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
            
            // Step 2: Rename tables back
            Schema::rename('birth_types', 'calving_types');
            Schema::rename('birth_problems', 'calving_problems');
            
            // Step 3: Re-add foreign keys pointing back to original tables
            Schema::table('birth_events', function (Blueprint $table) {
                $table->foreign('birthTypeId')->references('id')->on('calving_types')->onDelete('cascade');
                $table->foreign('birthProblemsId')->references('id')->on('calving_problems')->onDelete('cascade');
            });
        }
    }
};
