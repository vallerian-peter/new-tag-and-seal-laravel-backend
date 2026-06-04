<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            'Hot docking iron',
            'Rubber ring',
            'Scissors',
            'Surgical blade',
            'Other',
        ];

        foreach ($defaults as $name) {
            DB::table('tail_docking_methods')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('tail_docking_methods')
            ->whereIn('name', [
                'Hot docking iron',
                'Rubber ring',
                'Scissors',
                'Surgical blade',
                'Other',
            ])
            ->delete();
    }
};
