<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A collection centre receives any goods; the goods type is chosen on each
 * delivery. Earlier receipts were all milk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperative_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_intakes', 'commodity_code')) {
                $table->string('commodity_code', 40)->default('milk')->after('unit')->index();
            }
        });

        if (Schema::hasColumn('cooperative_collection_centres', 'commodity_codes')) {
            Schema::table('cooperative_collection_centres', function (Blueprint $table) {
                $table->dropColumn('commodity_codes');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cooperative_collection_centres', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_collection_centres', 'commodity_codes')) {
                $table->json('commodity_codes')->nullable()->after('default_unit');
            }
        });

        if (Schema::hasColumn('cooperative_intakes', 'commodity_code')) {
            Schema::table('cooperative_intakes', function (Blueprint $table) {
                $table->dropIndex(['commodity_code']);
                $table->dropColumn('commodity_code');
            });
        }
    }
};
