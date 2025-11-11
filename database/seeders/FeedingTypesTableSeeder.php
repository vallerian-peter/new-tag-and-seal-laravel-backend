<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeedingTypesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if the table is empty
        if (DB::table('feeding_types')->count() > 0) {
            $this->command->info('Feeding types table is not empty. Skipping seeding.');
            return;
        }

        $feedingTypes = [
            ['name' => 'Hay', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Maize bran', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Rice bran', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Wheat bran', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cotton seed cake', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sunflower cake', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Grass', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Silage', 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('feeding_types')->insert($feedingTypes);
        $this->command->info('Seeded feeding types successfully!');
    }
}
