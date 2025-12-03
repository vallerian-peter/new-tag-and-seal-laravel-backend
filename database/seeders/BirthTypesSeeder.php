<?php

namespace Database\Seeders;

use App\Models\LivestockType;
use App\Models\BirthType;
use Illuminate\Database\Seeder;

class BirthTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get livestock types
        $cattleType = LivestockType::where('name', 'like', '%cattle%')
            ->orWhere('name', 'like', '%ngombe%')
            ->orWhere('name', 'like', '%Cattle%')
            ->first();

        $pigType = LivestockType::where('name', 'like', '%swine%')
            ->orWhere('name', 'like', '%pig%')
            ->orWhere('name', 'like', '%nguruwe%')
            ->orWhere('name', 'like', '%Pig%')
            ->orWhere('name', 'like', '%Swine%')
            ->first();

        // Generic birth types (applicable to all livestock)
        $genericTypes = [
            ['name' => 'Normal', 'livestockTypeId' => null],
            ['name' => 'Abnormal', 'livestockTypeId' => null],
        ];

        // Cattle-specific types (if cattle type exists)
        $cattleTypes = [];
        if ($cattleType) {
            $cattleTypes = [
                ['name' => 'Normal Calving', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Abnormal Calving', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Assisted Calving', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Caesarean Section', 'livestockTypeId' => $cattleType->id],
            ];
        }

        // Pig-specific types (if pig type exists)
        $pigTypes = [];
        if ($pigType) {
            $pigTypes = [
                ['name' => 'Normal Farrowing', 'livestockTypeId' => $pigType->id],
                ['name' => 'Abnormal Farrowing', 'livestockTypeId' => $pigType->id],
                ['name' => 'Assisted Farrowing', 'livestockTypeId' => $pigType->id],
            ];
        }

        // Seed all types
        $allTypes = array_merge($genericTypes, $cattleTypes, $pigTypes);
        
        foreach ($allTypes as $type) {
            BirthType::firstOrCreate(
                ['name' => $type['name'], 'livestockTypeId' => $type['livestockTypeId']],
                $type
            );
        }

        $this->command->info('Birth types seeded successfully!');
    }
}

