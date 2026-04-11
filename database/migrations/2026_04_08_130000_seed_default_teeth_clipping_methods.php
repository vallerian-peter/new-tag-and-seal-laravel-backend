<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $defaults = [
            ['name' => 'Manual clipping'],
            ['name' => 'Electric grinder'],
            ['name' => 'Nail nipper'],
            ['name' => 'Teeth file'],
            ['name' => 'Other'],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('teeth_clipping_methods')
                ->where('name', $row['name'])
                ->exists();

            if (! $exists) {
                DB::table('teeth_clipping_methods')->insert([
                    'name' => $row['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('teeth_clipping_methods')
            ->whereIn('name', [
                'Manual clipping',
                'Electric grinder',
                'Nail nipper',
                'Teeth file',
                'Other',
            ])
            ->delete();
    }
};
