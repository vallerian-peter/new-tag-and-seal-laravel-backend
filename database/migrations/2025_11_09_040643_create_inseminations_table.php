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
        Schema::create('inseminations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            // replaced snake_case with camelCase
            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('lastHeatDate')->nullable();

            $table->foreignId('currentHeatTypeId')
                ->constrained('heat_types')
                ->cascadeOnDelete();

            $table->foreignId('inseminationServiceId')
                ->constrained('insemination_services')
                ->cascadeOnDelete();

            $table->foreignId('semenStrawTypeId')
                ->constrained('semen_straw_types')
                ->cascadeOnDelete();

            $table->string('inseminationDate')->nullable();

            $table->string('bullCode')->nullable();
            $table->string('bullBreed')->nullable();
            $table->string('semenProductionDate')->nullable();
            $table->string('productionCountry')->nullable();
            $table->string('semenBatchNumber')->nullable();
            $table->string('internationalId')->nullable();
            $table->string('aiCode')->nullable();
            $table->string('manufacturerName')->nullable();
            $table->string('semenSupplier')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inseminations');
    }
};
