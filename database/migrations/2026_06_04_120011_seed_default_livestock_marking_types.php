<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            'Branding',
            'Ear notch',
            'Ear tag',
            'Paint',
            'Tattoo',
            'Other',
        ];

        foreach ($defaults as $name) {
            DB::table('livestock_marking_types')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('livestock_marking_types')
            ->whereIn('name', [
                'Branding',
                'Ear notch',
                'Ear tag',
                'Paint',
                'Tattoo',
                'Other',
            ])
            ->delete();
    }
};
