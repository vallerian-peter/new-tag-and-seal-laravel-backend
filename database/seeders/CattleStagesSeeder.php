<?php

namespace Database\Seeders;

use App\Models\LivestockType;
use App\Models\Stage;
use Illuminate\Database\Seeder;

class CattleStagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get Cattle livestock type
        $cattleType = LivestockType::where('name', 'like', '%cattle%')
            ->orWhere('name', 'like', '%ngombe%')
            ->orWhere('name', 'like', '%Cattle%')
            ->first();

        if (!$cattleType) {
            $this->command->warn('Cattle livestock type not found. Please create it first.');
            return;
        }

        $cattleStages = [
            // Female stages
            ['name' => 'Calf', 'livestockTypeId' => $cattleType->id],
            ['name' => 'Weaner', 'livestockTypeId' => $cattleType->id],
            ['name' => 'Heifer', 'livestockTypeId' => $cattleType->id],
            ['name' => 'Cow', 'livestockTypeId' => $cattleType->id],
            
            // Male stages
            ['name' => 'Steer', 'livestockTypeId' => $cattleType->id], // Castrated
            ['name' => 'Bull', 'livestockTypeId' => $cattleType->id], // Intact
        ];

        foreach ($cattleStages as $stage) {
            Stage::firstOrCreate(
                ['name' => $stage['name'], 'livestockTypeId' => $stage['livestockTypeId']],
                $stage
            );
        }

        $this->command->info('Cattle stages seeded successfully!');
    }
}

