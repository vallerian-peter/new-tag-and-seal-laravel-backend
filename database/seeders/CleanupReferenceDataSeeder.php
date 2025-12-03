<?php

namespace Database\Seeders;

use App\Models\BirthType;
use App\Models\BirthProblem;
use Illuminate\Database\Seeder;

class CleanupReferenceDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * This seeder cleans up duplicate and redundant reference data.
     */
    public function run(): void
    {
        // 1. Remove duplicate "Fetatomy" from generic (keep only in Cattle)
        $genericFetatomy = BirthProblem::where('name', 'Fetatomy')
            ->whereNull('livestockTypeId')
            ->first();
        
        if ($genericFetatomy) {
            $genericFetatomy->delete();
            $this->command->info('Removed duplicate "Fetatomy" from generic birth problems');
        }

        // 2. Remove duplicate "None" from generic (keep "No Problem")
        $genericNone = BirthProblem::where('name', 'None')
            ->whereNull('livestockTypeId')
            ->first();
        
        if ($genericNone) {
            $genericNone->delete();
            $this->command->info('Removed duplicate "None" from generic birth problems (keeping "No Problem")');
        }

        // 3. Remove redundant "Natural" from generic birth types (keep "Normal")
        $genericNatural = BirthType::where('name', 'Natural')
            ->whereNull('livestockTypeId')
            ->first();
        
        if ($genericNatural) {
            $genericNatural->delete();
            $this->command->info('Removed redundant "Natural" from generic birth types (keeping "Normal")');
        }

        // 4. Review "Respiratory problem" - remove if not appropriate for birth problems
        $respiratoryProblem = BirthProblem::where('name', 'Respiratory problem')
            ->whereNull('livestockTypeId')
            ->first();
        
        if ($respiratoryProblem) {
            // Uncomment to remove if confirmed not appropriate
            // $respiratoryProblem->delete();
            // $this->command->info('Removed "Respiratory problem" from generic birth problems');
            $this->command->warn('Found "Respiratory problem" - please review if this should be a birth problem');
        }

        $this->command->info('Reference data cleanup completed!');
    }
}

