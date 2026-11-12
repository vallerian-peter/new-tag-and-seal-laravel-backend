<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cooperative_mcus', 'cooperative_amcos'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('tin_number', 20)->nullable()->after('registration_number');
                $table->string('website')->nullable()->after('tin_number');
                $table->string('contact_title', 120)->nullable()->after('contact_name');
                $table->string('contact_email')->nullable()->after('contact_phone');
            });
        }
        Schema::table('cooperative_mcus', function (Blueprint $table) {
            $table->unsignedBigInteger('ward_id')->nullable()->after('district_id');
            $table->unsignedBigInteger('village_id')->nullable()->after('ward_id');
        });
    }

    public function down(): void
    {
        Schema::table('cooperative_mcus', fn (Blueprint $table) => $table->dropColumn(['ward_id', 'village_id']));
        foreach (['cooperative_mcus', 'cooperative_amcos'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['tin_number', 'website', 'contact_title', 'contact_email']));
        }
    }
};
