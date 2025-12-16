<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ensure doctrine/dbal is installed: composer require doctrine/dbal
        Schema::table('milkings', function (Blueprint $table) {
            if (Schema::hasColumn('milkings', 'lactometerReading')) {
                $table->string('lactometerReading')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'solid')) {
                $table->string('solid')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'solidNonFat')) {
                $table->string('solidNonFat')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'protein')) {
                $table->string('protein')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'correctedLactometerReading')) {
                $table->string('correctedLactometerReading')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'totalSolids')) {
                $table->string('totalSolids')->nullable()->change();
            }
            if (Schema::hasColumn('milkings', 'colonyFormingUnits')) {
                $table->string('colonyFormingUnits')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('milkings', function (Blueprint $table) {
            if (Schema::hasColumn('milkings', 'lactometerReading')) {
                $table->string('lactometerReading')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'solid')) {
                $table->string('solid')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'solidNonFat')) {
                $table->string('solidNonFat')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'protein')) {
                $table->string('protein')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'correctedLactometerReading')) {
                $table->string('correctedLactometerReading')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'totalSolids')) {
                $table->string('totalSolids')->nullable(false)->change();
            }
            if (Schema::hasColumn('milkings', 'colonyFormingUnits')) {
                $table->string('colonyFormingUnits')->nullable(false)->change();
            }
        });
    }
};
