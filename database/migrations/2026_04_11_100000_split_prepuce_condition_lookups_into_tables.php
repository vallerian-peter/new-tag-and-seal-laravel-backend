<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table per picklist (aligned with medicines, diseases, etc.).
     * Sync exposes separate keys + medicine-style rows via PrepuceConditionLookupController::referenceDataForSync().
     */
    private const TABLES = [
        'prepuce_condition_types' => 'condition_type',
        'prepuce_severities' => 'severity',
        'prepuce_clinical_signs' => 'clinical_sign',
        'prepuce_cause_risks' => 'cause_risk',
        'prepuce_treatments_given' => 'treatment_given',
        'prepuce_breeding_statuses' => 'breeding_status',
        'prepuce_healing_statuses' => 'healing_status',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (Schema::hasTable($tableName)) {
                continue;
            }
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('label_en');
                $table->string('label_sw')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('prepuce_condition_lookups')) {
            $this->seedFromDefinitions();

            return;
        }

        $rows = DB::table('prepuce_condition_lookups')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $categoryToTable = array_flip(self::TABLES);

        foreach ($rows as $row) {
            $targetTable = $categoryToTable[$row->category] ?? null;
            if ($targetTable === null || ! Schema::hasTable($targetTable)) {
                continue;
            }
            $exists = DB::table($targetTable)->where('code', $row->code)->exists();
            if ($exists) {
                continue;
            }
            DB::table($targetTable)->insert([
                'code' => $row->code,
                'label_en' => $row->label_en,
                'label_sw' => $row->label_sw,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        Schema::dropIfExists('prepuce_condition_lookups');
    }

    public function down(): void
    {
        if (! Schema::hasTable('prepuce_condition_types')) {
            return;
        }

        Schema::create('prepuce_condition_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64);
            $table->string('code', 64);
            $table->string('label_en');
            $table->string('label_sw')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category', 'code']);
        });

        foreach (self::TABLES as $tableName => $category) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $sort = 0;
            foreach (DB::table($tableName)->orderBy('id')->get() as $row) {
                DB::table('prepuce_condition_lookups')->insert([
                    'category' => $category,
                    'code' => $row->code,
                    'label_en' => $row->label_en,
                    'label_sw' => $row->label_sw,
                    'sort_order' => ++$sort,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function seedFromDefinitions(): void
    {
        $now = now();
        $defs = [
            'prepuce_condition_types' => [
                ['preputial_swelling', 'Preputial swelling', 'Uvimbe wa govi'],
                ['wound_laceration', 'Wound / laceration', 'Jeraha'],
                ['infection', 'Infection', 'Maambukizo'],
                ['discharge', 'Discharge', 'Kutoka uchafu'],
                ['prolapse', 'Prolapse', 'Kutokea nje'],
                ['other', 'Other', 'Nyingine'],
            ],
            'prepuce_severities' => [
                ['mild', 'Mild', 'Hafifu'],
                ['moderate', 'Moderate', 'Wastani'],
                ['severe', 'Severe', 'Kali'],
            ],
            'prepuce_clinical_signs' => [
                ['swelling', 'Swelling', 'Uvimbe'],
                ['pain', 'Pain', 'Maumivu'],
                ['bleeding', 'Bleeding', 'Kutokwa na damu'],
                ['pus_discharge', 'Pus discharge', 'Pus'],
                ['difficulty_mating', 'Difficulty mating', 'Ugumu wa kupandana'],
                ['difficulty_urinating', 'Difficulty urinating', 'Ugumu wa kukojoa'],
            ],
            'prepuce_cause_risks' => [
                ['injury_mating', 'Injury during mating', 'Jeraha wakati wa kupandana'],
                ['bacterial_infection', 'Infection (bacterial)', 'Maambukizo (bakteria)'],
                ['poor_hygiene', 'Poor hygiene', 'Usafi duni'],
                ['parasites', 'Parasites', 'Vimelea'],
                ['unknown', 'Unknown', 'Haijulikani'],
            ],
            'prepuce_treatments_given' => [
                ['cleaning_flushing', 'Cleaning / flushing', 'Kusafisha / kuosha'],
                ['antibiotics', 'Antibiotics', 'Antibiotiki'],
                ['anti_inflammatory', 'Anti-inflammatory', 'Dawa za kuondoa uvimbe/maumivu'],
                ['surgery', 'Surgical intervention', 'Upasuaji'],
                ['traditional', 'Traditional treatment', 'Tiba ya jadi'],
                ['other', 'Other', 'Nyingine'],
            ],
            'prepuce_breeding_statuses' => [
                ['active_breeder', 'Active breeder', 'Anayepanda'],
                ['temporarily_unfit', 'Temporarily unfit', 'Hafai kwa muda'],
                ['permanently_unfit', 'Permanently unfit', 'Hafai kabisa'],
            ],
            'prepuce_healing_statuses' => [
                ['recovering', 'Recovering', 'Inapona'],
                ['recovered', 'Recovered', 'Imepona'],
                ['not_improving', 'Not improving', 'Haiboreki'],
                ['worsening', 'Worsening', 'Inazidi kuharibika'],
            ],
        ];

        foreach ($defs as $table => $items) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($items as [$code, $labelEn, $labelSw]) {
                if (DB::table($table)->where('code', $code)->exists()) {
                    continue;
                }
                DB::table($table)->insert([
                    'code' => $code,
                    'label_en' => $labelEn,
                    'label_sw' => $labelSw,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
