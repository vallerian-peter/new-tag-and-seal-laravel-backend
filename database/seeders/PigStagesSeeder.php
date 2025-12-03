<?php

namespace Database\Seeders;

use App\Models\LivestockType;
use App\Models\Stage;
use Illuminate\Database\Seeder;

class PigStagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get Pig livestock type (Swine)
        $pigType = LivestockType::where('name', 'like', '%swine%')
            ->orWhere('name', 'like', '%pig%')
            ->orWhere('name', 'like', '%nguruwe%')
            ->orWhere('name', 'like', '%Pig%')
            ->orWhere('name', 'like', '%Swine%')
            ->first();

        if (!$pigType) {
            $this->command->warn('Pig livestock type not found. Please create it first.');
            return;
        }

        $pigStages = [
            // Female stages
            ['name' => 'Piglet', 'livestockTypeId' => $pigType->id],
            ['name' => 'Weaner', 'livestockTypeId' => $pigType->id],
            ['name' => 'Gilt', 'livestockTypeId' => $pigType->id],
            ['name' => 'Sow', 'livestockTypeId' => $pigType->id],
            
            // Male stages
            ['name' => 'Barrow', 'livestockTypeId' => $pigType->id], // Castrated
            ['name' => 'Stag', 'livestockTypeId' => $pigType->id], // Castrated
            ['name' => 'Boar', 'livestockTypeId' => $pigType->id], // Intact
        ];

        foreach ($pigStages as $stage) {
            Stage::firstOrCreate(
                ['name' => $stage['name'], 'livestockTypeId' => $stage['livestockTypeId']],
                $stage
            );
        }

        $this->command->info('Pig stages seeded successfully!');
    }
}

