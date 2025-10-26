<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Migrate Reference Data from Old Database (itag_dev) to New Database (new_tag_and_seal)
 *
 * This seeder migrates:
 * - Legal Statuses
 * - Species
 * - Livestock Types
 * - Breeds
 * - Livestock Obtained Methods
 *
 * Usage:
 * php artisan db:seed --class=MigrateReferenceDataSeeder
 */
class MigrateReferenceDataSeeder extends Seeder
{
    /**
     * Connection to old database (configured in config/database.php)
     */
    private $oldDb = 'old_itag';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🔄 Starting reference data migration from itag_dev...');

        try {
            // 1. Migrate Legal Statuses
            $this->migrateLegalStatuses();

            // 2. Migrate Species
            $this->migrateSpecies();

            // 3. Migrate Livestock Types
            $this->migrateLivestockTypes();

            // 4. Migrate Breeds (depends on livestock_types)
            $this->migrateBreeds();

            // 5. Migrate Livestock Obtained Methods
            $this->migrateLivestockObtainedMethods();

            $this->command->info('✅ Reference data migration completed successfully!');

        } catch (\Exception $e) {
            $this->command->error('❌ Migration failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Migrate Legal Statuses
     */
    private function migrateLegalStatuses(): void
    {
        $this->command->info('  📋 Migrating legal_statuses...');

        // Get data from old database
        $oldData = DB::connection($this->oldDb)
            ->table('legal_statuses')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->get();

        if ($oldData->isEmpty()) {
            $this->command->warn('  ⚠️  No legal_statuses found in old database');
            return;
        }

        // Insert into new database
        foreach ($oldData as $item) {
            DB::table('legal_statuses')->updateOrInsert(
                ['id' => $item->id],
                [
                    'name' => $item->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            );
        }

        $this->command->info("  ✅ Migrated {$oldData->count()} legal_statuses");
    }

    /**
     * Migrate Species
     */
    private function migrateSpecies(): void
    {
        $this->command->info('  🐄 Migrating species...');

        // Get data from old database
        $oldData = DB::connection($this->oldDb)
            ->table('species')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->get();

        if ($oldData->isEmpty()) {
            $this->command->warn('  ⚠️  No species found in old database');
            return;
        }

        // Insert into new database
        foreach ($oldData as $item) {
            DB::table('species')->updateOrInsert(
                ['id' => $item->id],
                [
                    'name' => $item->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            );
        }

        $this->command->info("  ✅ Migrated {$oldData->count()} species");
    }

    /**
     * Migrate Livestock Types
     */
    private function migrateLivestockTypes(): void
    {
        $this->command->info('  🐮 Migrating livestock_types...');

        // Get data from old database
        $oldData = DB::connection($this->oldDb)
            ->table('livestock_types')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->get();

        if ($oldData->isEmpty()) {
            $this->command->warn('  ⚠️  No livestock_types found in old database');
            return;
        }

        // Insert into new database
        foreach ($oldData as $item) {
            DB::table('livestock_types')->updateOrInsert(
                ['id' => $item->id],
                [
                    'name' => $item->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            );
        }

        $this->command->info("  ✅ Migrated {$oldData->count()} livestock_types");
    }

    /**
     * Migrate Breeds (depends on livestock_types)
     */
    private function migrateBreeds(): void
    {
        $this->command->info('  🐂 Migrating breeds...');

        // Get data from old database (including group and livestock_type_id)
        $oldData = DB::connection($this->oldDb)
            ->table('breeds')
            ->select('id', 'name', 'group', 'livestock_type_id', 'created_at', 'updated_at')
            ->get();

        if ($oldData->isEmpty()) {
            $this->command->warn('  ⚠️  No breeds found in old database');
            return;
        }

        // Insert into new database
        foreach ($oldData as $item) {
            DB::table('breeds')->updateOrInsert(
                ['id' => $item->id],
                [
                    'name' => $item->name,
                    'group' => $item->group ?? 'Unknown',  // Use 'Unknown' if group is null
                    'livestockTypeId' => $item->livestock_type_id,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            );
        }

        $this->command->info("  ✅ Migrated {$oldData->count()} breeds");
    }

    /**
     * Migrate Livestock Obtained Methods
     */
    private function migrateLivestockObtainedMethods(): void
    {
        $this->command->info('  📦 Migrating livestock_obtained_methods...');

        // Get data from old database
        $oldData = DB::connection($this->oldDb)
            ->table('livestock_obtained_methods')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->get();

        if ($oldData->isEmpty()) {
            $this->command->warn('  ⚠️  No livestock_obtained_methods found in old database');
            return;
        }

        // Insert into new database
        foreach ($oldData as $item) {
            DB::table('livestock_obtained_methods')->updateOrInsert(
                ['id' => $item->id],
                [
                    'name' => $item->name,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            );
        }

        $this->command->info("  ✅ Migrated {$oldData->count()} livestock_obtained_methods");
    }
}

