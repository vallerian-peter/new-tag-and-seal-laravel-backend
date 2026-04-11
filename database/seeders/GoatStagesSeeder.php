<?php

namespace Database\Seeders;

use App\Models\LivestockType;
use App\Models\Stage;
use Illuminate\Database\Seeder;

class GoatStagesSeeder extends Seeder
{
    public function run(): void
    {
        $goatType = LivestockType::where('name', 'like', '%goat%')
            ->orWhere('name', 'like', '%mbuzi%')
            ->orWhere('name', 'like', '%Goat%')
            ->first();

        if (! $goatType) {
            $this->command->warn('Goat livestock type not found. Skipping goat stages.');

            return;
        }

        $stages = [
            ['name' => 'Kid', 'livestockTypeId' => $goatType->id],
            ['name' => 'Grower', 'livestockTypeId' => $goatType->id],
            ['name' => 'Adult', 'livestockTypeId' => $goatType->id],
        ];

        foreach ($stages as $stage) {
            Stage::firstOrCreate(
                ['name' => $stage['name'], 'livestockTypeId' => $stage['livestockTypeId']],
                $stage
            );
        }

        $this->command->info('Goat stages seeded successfully!');
    }
}
