<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperative_mcus', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_mcus', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->after('website');
            }
            if (! Schema::hasColumn('cooperative_mcus', 'all_regions')) {
                $table->boolean('all_regions')->default(false)->after('country_id');
            }
        });

        DB::table('cooperative_mcus')->whereNull('country_id')->whereNotNull('region_id')->orderBy('id')
            ->each(fn ($mcu) => DB::table('cooperative_mcus')->where('id', $mcu->id)->update([
                'country_id' => DB::table('regions')->where('id', $mcu->region_id)->value('countryId'),
            ]));
    }

    public function down(): void
    {
        Schema::table('cooperative_mcus', fn (Blueprint $table) => $table->dropColumn(['country_id', 'all_regions']));
    }
};
