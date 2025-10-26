<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MigrateLocationsFromOldDatabase extends Seeder
{
    /**
     * Old database connection name (configure in config/database.php)
     */
    private const OLD_DB = 'old_itag';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting location data migration from old database...');

        // Migrate in correct order (parent to child)
        $this->migrateCountries();
        $this->migrateRegions();
        $this->migrateDistricts();
        $this->migrateWards();
        $this->migrateVillages();

        $this->command->info('Location data migration completed successfully!');
    }

    /**
     * Migrate countries from old database.
     */
    private function migrateCountries(): void
    {
        $this->command->info('Migrating countries...');

        $oldCountries = DB::connection(self::OLD_DB)
            ->table('countries')
            ->select('id', 'name', 'short_name', 'created_at', 'updated_at')
            ->get();

        foreach ($oldCountries as $oldCountry) {
            DB::table('countries')->updateOrInsert(
                ['id' => $oldCountry->id],
                [
                    'name' => $oldCountry->name,
                    'shortName' => $oldCountry->short_name ?? strtoupper(substr($oldCountry->name, 0, 2)),
                    'created_at' => $oldCountry->created_at ?? now(),
                    'updated_at' => $oldCountry->updated_at ?? now(),
                ]
            );
        }

        $this->command->info("Migrated {$oldCountries->count()} countries");
    }

    /**
     * Migrate regions from old database.
     */
    private function migrateRegions(): void
    {
        $this->command->info('Migrating regions...');

        $oldRegions = DB::connection(self::OLD_DB)
            ->table('regions')
            ->select('id', 'name', 'country_id', 'created_at', 'updated_at')
            ->get();

        foreach ($oldRegions as $oldRegion) {
            DB::table('regions')->updateOrInsert(
                ['id' => $oldRegion->id],
                [
                    'name' => $oldRegion->name,
                    'shortName' => strtoupper(substr($oldRegion->name, 0, 3)),
                    'countryId' => $oldRegion->country_id,
                    'created_at' => $oldRegion->created_at ?? now(),
                    'updated_at' => $oldRegion->updated_at ?? now(),
                ]
            );
        }

        $this->command->info("Migrated {$oldRegions->count()} regions");
    }

    /**
     * Migrate districts from old database.
     */
    private function migrateDistricts(): void
    {
        $this->command->info('Migrating districts...');

        $oldDistricts = DB::connection(self::OLD_DB)
            ->table('districts')
            ->select('id', 'name', 'region_id', 'created_at', 'updated_at')
            ->get();

        foreach ($oldDistricts as $oldDistrict) {
            DB::table('districts')->updateOrInsert(
                ['id' => $oldDistrict->id],
                [
                    'name' => $oldDistrict->name,
                    'regionId' => $oldDistrict->region_id,
                    'created_at' => $oldDistrict->created_at ?? now(),
                    'updated_at' => $oldDistrict->updated_at ?? now(),
                ]
            );
        }

        $this->command->info("Migrated {$oldDistricts->count()} districts");
    }

    /**
     * Migrate wards from old database.
     */
    private function migrateWards(): void
    {
        $this->command->info('Migrating wards...');

        $oldWards = DB::connection(self::OLD_DB)
            ->table('wards')
            ->select('id', 'name', 'district_id', 'created_at', 'updated_at')
            ->get();

        foreach ($oldWards as $oldWard) {
            DB::table('wards')->updateOrInsert(
                ['id' => $oldWard->id],
                [
                    'name' => $oldWard->name,
                    'districtId' => $oldWard->district_id,
                    'created_at' => $oldWard->created_at ?? now(),
                    'updated_at' => $oldWard->updated_at ?? now(),
                ]
            );
        }

        $this->command->info("Migrated {$oldWards->count()} wards");
    }

    /**
     * Migrate villages from old database.
     */
    private function migrateVillages(): void
    {
        $this->command->info('Migrating villages...');

        $oldVillages = DB::connection(self::OLD_DB)
            ->table('villages')
            ->select('id', 'name', 'ward_id', 'created_at', 'updated_at')
            ->get();

        foreach ($oldVillages as $oldVillage) {
            DB::table('villages')->updateOrInsert(
                ['id' => $oldVillage->id],
                [
                    'name' => $oldVillage->name,
                    'wardId' => $oldVillage->ward_id,
                    'created_at' => $oldVillage->created_at ?? now(),
                    'updated_at' => $oldVillage->updated_at ?? now(),
                ]
            );
        }

        $this->command->info("Migrated {$oldVillages->count()} villages");
    }
}

