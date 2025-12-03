<?php

namespace Database\Seeders;

use App\Models\LivestockType;
use App\Models\BirthProblem;
use Illuminate\Database\Seeder;

class BirthProblemsSeeder extends Seeder
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

        // Generic birth problems (applicable to all livestock)
        $genericProblems = [
            ['name' => 'None', 'livestockTypeId' => null],
            ['name' => 'Dystocia', 'livestockTypeId' => null],
            ['name' => 'Retained Placenta', 'livestockTypeId' => null],
        ];

        // Cattle-specific problems (if cattle type exists)
        $cattleProblems = [];
        if ($cattleType) {
            $cattleProblems = [
                ['name' => 'Surgical Procedure', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Fetatomy', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Calving Related Nerve Paralysis', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Milk Fever', 'livestockTypeId' => $cattleType->id],
                ['name' => 'Mastitis', 'livestockTypeId' => $cattleType->id],
            ];
        }

        // Pig-specific problems (if pig type exists)
        $pigProblems = [];
        if ($pigType) {
            $pigProblems = [
                ['name' => 'Stillborn Piglets', 'livestockTypeId' => $pigType->id],
                ['name' => 'Weak Piglets', 'livestockTypeId' => $pigType->id],
                ['name' => 'Prolonged Farrowing', 'livestockTypeId' => $pigType->id],
            ];
        }

        // Seed all problems
        $allProblems = array_merge($genericProblems, $cattleProblems, $pigProblems);
        
        foreach ($allProblems as $problem) {
            BirthProblem::firstOrCreate(
                ['name' => $problem['name'], 'livestockTypeId' => $problem['livestockTypeId']],
                $problem
            );
        }

        $this->command->info('Birth problems seeded successfully!');
    }
}

