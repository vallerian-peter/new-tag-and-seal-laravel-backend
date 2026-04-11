<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        $add = function (string $category, string $code, string $labelEn, ?string $labelSw, int $sort) use (&$rows, $now) {
            $rows[] = [
                'category' => $category,
                'code' => $code,
                'label_en' => $labelEn,
                'label_sw' => $labelSw,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // condition_type
        $add('condition_type', 'preputial_swelling', 'Preputial swelling', 'Uvimbe wa govi', 1);
        $add('condition_type', 'wound_laceration', 'Wound / laceration', 'Jeraha', 2);
        $add('condition_type', 'infection', 'Infection', 'Maambukizo', 3);
        $add('condition_type', 'discharge', 'Discharge', 'Kutoka uchafu', 4);
        $add('condition_type', 'prolapse', 'Prolapse', 'Kutokea nje', 5);
        $add('condition_type', 'other', 'Other', 'Nyingine', 99);

        // severity
        $add('severity', 'mild', 'Mild', 'Hafifu', 1);
        $add('severity', 'moderate', 'Moderate', 'Wastani', 2);
        $add('severity', 'severe', 'Severe', 'Kali', 3);

        // clinical_sign (multi)
        $add('clinical_sign', 'swelling', 'Swelling', 'Uvimbe', 1);
        $add('clinical_sign', 'pain', 'Pain', 'Maumivu', 2);
        $add('clinical_sign', 'bleeding', 'Bleeding', 'Kutokwa na damu', 3);
        $add('clinical_sign', 'pus_discharge', 'Pus discharge', 'Pus', 4);
        $add('clinical_sign', 'difficulty_mating', 'Difficulty mating', 'Ugumu wa kupandana', 5);
        $add('clinical_sign', 'difficulty_urinating', 'Difficulty urinating', 'Ugumu wa kukojoa', 6);

        // cause_risk
        $add('cause_risk', 'injury_mating', 'Injury during mating', 'Jeraha wakati wa kupandana', 1);
        $add('cause_risk', 'bacterial_infection', 'Infection (bacterial)', 'Maambukizo (bakteria)', 2);
        $add('cause_risk', 'poor_hygiene', 'Poor hygiene', 'Usafi duni', 3);
        $add('cause_risk', 'parasites', 'Parasites', 'Vimelea', 4);
        $add('cause_risk', 'unknown', 'Unknown', 'Haijulikani', 99);

        // treatment_given (multi)
        $add('treatment_given', 'cleaning_flushing', 'Cleaning / flushing', 'Kusafisha / kuosha', 1);
        $add('treatment_given', 'antibiotics', 'Antibiotics', 'Antibiotiki', 2);
        $add('treatment_given', 'anti_inflammatory', 'Anti-inflammatory', 'Dawa za kuondoa uvimbe/maumivu', 3);
        $add('treatment_given', 'surgery', 'Surgical intervention', 'Upasuaji', 4);
        $add('treatment_given', 'traditional', 'Traditional treatment', 'Tiba ya jadi', 5);
        $add('treatment_given', 'other', 'Other', 'Nyingine', 99);

        // Administration route uses shared `administration_routes` + administrationRouteId on logs.
        // Vet / extension officer use vetId + extensionOfficerId (same as dewormings / vaccinations).

        // breeding_status
        $add('breeding_status', 'active_breeder', 'Active breeder', 'Anayepanda', 1);
        $add('breeding_status', 'temporarily_unfit', 'Temporarily unfit', 'Hafai kwa muda', 2);
        $add('breeding_status', 'permanently_unfit', 'Permanently unfit', 'Hafai kabisa', 3);

        // healing_status
        $add('healing_status', 'recovering', 'Recovering', 'Inapona', 1);
        $add('healing_status', 'recovered', 'Recovered', 'Imepona', 2);
        $add('healing_status', 'not_improving', 'Not improving', 'Haiboreki', 3);
        $add('healing_status', 'worsening', 'Worsening', 'Inazidi kuharibika', 4);

        foreach ($rows as $row) {
            $exists = DB::table('prepuce_condition_lookups')
                ->where('category', $row['category'])
                ->where('code', $row['code'])
                ->exists();
            if (! $exists) {
                DB::table('prepuce_condition_lookups')->insert($row);
            }
        }
    }

    public function down(): void
    {
        DB::table('prepuce_condition_lookups')->delete();
    }
};
