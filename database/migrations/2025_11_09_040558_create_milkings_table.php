<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('milkings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->foreignId('milkingMethodId')->nullable()->constrained('milking_methods')->cascadeOnDelete();

            $table->string('amount');
            $table->string('lactometerReading');
            $table->string('solid');
            $table->string('solidNonFat');
            $table->string('protein');
            $table->string('correctedLactometerReading');
            $table->string('totalSolids');
            $table->string('colonyFormingUnits');
            $table->string('acidity')->nullable();

            $table->enum('session', ['morning', 'evening', 'night', 'midnight'])->default('morning');
            $table->enum('status', ['pending', 'not-active', 'active'])->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milkings');
    }
};
