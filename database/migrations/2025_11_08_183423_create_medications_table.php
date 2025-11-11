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
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->foreignId('diseaseId')->constrained('diseases')->cascadeOnDelete();
            $table->foreignId('medicineId')->constrained('medicines')->cascadeOnDelete();

            $table->string('quantity')->comment('Quantity of the medicine with unit')->nullable();
            $table->string('withdrawalPeriod')->comment('Number of Days for a meat or milk with unit')->nullable();
            $table->string('medicationDate')->comment('Date a Livestock medicated')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
