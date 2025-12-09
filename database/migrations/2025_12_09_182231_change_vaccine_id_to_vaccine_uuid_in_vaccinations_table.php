<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('vaccinations')) {
            Log::warning('⚠️ Table vaccinations does not exist, skipping migration...');
            return;
        }

        // Check if vaccineId column exists
        $hasVaccineId = Schema::hasColumn('vaccinations', 'vaccineId');
        $hasVaccineUuid = Schema::hasColumn('vaccinations', 'vaccineUuid');

        if ($hasVaccineId) {
            // Step 1: Migrate data from vaccineId to vaccineUuid
            if (!$hasVaccineUuid) {
                // Add vaccineUuid column first
                Schema::table('vaccinations', function (Blueprint $table) {
                    $table->string('vaccineUuid', 100)->nullable()->after('livestockUuid');
                });
                Log::info('✅ Added vaccineUuid column to vaccinations table');
            }

            // Migrate existing data: look up UUID from vaccines table
            DB::statement('
                UPDATE vaccinations v
                INNER JOIN vaccines vac ON v.vaccineId = vac.id
                SET v.vaccineUuid = vac.uuid
                WHERE v.vaccineId IS NOT NULL
            ');
            Log::info('✅ Migrated data from vaccineId to vaccineUuid');

            // Step 2: Drop foreign key constraint if it exists
            // Laravel creates foreign key names like: table_column_foreign
            try {
                // Try the standard Laravel naming convention first
                Schema::table('vaccinations', function (Blueprint $table) {
                    $table->dropForeign(['vaccineId']);
                });
                Log::info('✅ Dropped foreign key constraint on vaccineId');
            } catch (\Exception $e) {
                // If that fails, try to find the actual constraint name
                try {
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'vaccinations'
                        AND COLUMN_NAME = 'vaccineId'
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");

                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE vaccinations DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
                        Log::info("✅ Dropped foreign key: {$fk->CONSTRAINT_NAME}");
                    }
                } catch (\Exception $e2) {
                    Log::warning("⚠️ Could not drop foreign key (may not exist): {$e2->getMessage()}");
                }
            }

            // Step 3: Drop index on vaccineId if it exists (after foreign key is dropped)
            try {
                $indexes = DB::select("
                    SELECT INDEX_NAME
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'vaccinations'
                    AND COLUMN_NAME = 'vaccineId'
                    AND INDEX_NAME != 'PRIMARY'
                ");

                foreach ($indexes as $index) {
                    Schema::table('vaccinations', function (Blueprint $table) use ($index) {
                        $table->dropIndex([$index->INDEX_NAME]);
                    });
                    Log::info("✅ Dropped index: {$index->INDEX_NAME}");
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Could not drop index (may not exist): {$e->getMessage()}");
            }

            // Step 4: Drop vaccineId column
            Schema::table('vaccinations', function (Blueprint $table) {
                $table->dropColumn('vaccineId');
            });
            Log::info('✅ Dropped vaccineId column from vaccinations table');
        }

        // Step 5: Add index on vaccineUuid if it doesn't exist
        if ($hasVaccineUuid || !$hasVaccineId) {
            $indexes = DB::select("
                SELECT INDEX_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'vaccinations'
                AND COLUMN_NAME = 'vaccineUuid'
            ");

            if (empty($indexes)) {
                Schema::table('vaccinations', function (Blueprint $table) {
                    $table->index('vaccineUuid');
                });
                Log::info('✅ Added index on vaccineUuid column');
            } else {
                Log::info('✅ Index on vaccineUuid already exists');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('vaccinations')) {
            return;
        }

        $hasVaccineUuid = Schema::hasColumn('vaccinations', 'vaccineUuid');
        $hasVaccineId = Schema::hasColumn('vaccinations', 'vaccineId');

        if ($hasVaccineUuid && !$hasVaccineId) {
            // Drop index on vaccineUuid
            try {
                $indexes = DB::select("
                    SELECT INDEX_NAME
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'vaccinations'
                    AND COLUMN_NAME = 'vaccineUuid'
                    AND INDEX_NAME != 'PRIMARY'
                ");

                foreach ($indexes as $index) {
                    Schema::table('vaccinations', function (Blueprint $table) use ($index) {
                        $table->dropIndex([$index->INDEX_NAME]);
                    });
                }
            } catch (\Exception $e) {
                // Ignore
            }

            // Add vaccineId column
            Schema::table('vaccinations', function (Blueprint $table) {
                $table->foreignId('vaccineId')->nullable()->after('livestockUuid');
            });

            // Migrate data back (if possible)
            DB::statement('
                UPDATE vaccinations v
                INNER JOIN vaccines vac ON v.vaccineUuid = vac.uuid
                SET v.vaccineId = vac.id
                WHERE v.vaccineUuid IS NOT NULL
            ');

            // Add foreign key
            Schema::table('vaccinations', function (Blueprint $table) {
                $table->foreign('vaccineId')->references('id')->on('vaccines')->onDelete('cascade');
            });

            // Drop vaccineUuid column
            Schema::table('vaccinations', function (Blueprint $table) {
                $table->dropColumn('vaccineUuid');
            });
        }
    }
};
