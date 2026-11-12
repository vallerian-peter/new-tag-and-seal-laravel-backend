<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a cooperative collects (milk today, wool or others later) is data, not
 * code. Centres also gain ward and village.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cooperative_commodities')) {
            Schema::create('cooperative_commodities', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name');
                $table->string('name_sw')->nullable();
                $table->string('default_unit', 20)->default('l');
                $table->string('icon', 60)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $now = now();
        foreach ([
            ['code' => 'milk', 'name' => 'Milk', 'name_sw' => 'Maziwa', 'default_unit' => 'l', 'icon' => 'water_drop', 'sort_order' => 1, 'is_active' => true],
            ['code' => 'wool', 'name' => 'Wool', 'name_sw' => 'Sufu', 'default_unit' => 'kg', 'icon' => 'texture', 'sort_order' => 2, 'is_active' => false],
        ] as $commodity) {
            if (! DB::table('cooperative_commodities')->where('code', $commodity['code'])->exists()) {
                DB::table('cooperative_commodities')->insert([...$commodity, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::table('cooperative_collection_centres', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_collection_centres', 'commodity_codes')) {
                $table->json('commodity_codes')->nullable()->after('default_unit');
            }
            if (! Schema::hasColumn('cooperative_collection_centres', 'ward_id')) {
                $table->unsignedBigInteger('ward_id')->nullable()->after('district_id');
            }
            if (! Schema::hasColumn('cooperative_collection_centres', 'village_id')) {
                $table->unsignedBigInteger('village_id')->nullable()->after('ward_id');
            }
        });

        DB::table('cooperative_collection_centres')
            ->whereNull('commodity_codes')
            ->update(['commodity_codes' => json_encode(['milk'])]);
    }

    public function down(): void
    {
        Schema::table('cooperative_collection_centres', function (Blueprint $table) {
            foreach (['commodity_codes', 'ward_id', 'village_id'] as $column) {
                if (Schema::hasColumn('cooperative_collection_centres', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('cooperative_commodities');
    }
};
