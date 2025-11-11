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
        Schema::create('calvings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->string('startDate');
            $table->string('endDate')->nullable(); // In case dryoff is still ongoing

            $table->foreignId('calvingTypeId')->constrained('calving_types')->cascadeOnDelete();
            $table->foreignId('calvingProblemsId')->nullable()->constrained('calving_problems')->cascadeOnDelete();
            $table->foreignId('reproductiveProblemId')->nullable()->constrained('reproductive_problems')->cascadeOnDelete();

            $table->string('remarks')->nullable();
            $table->enum('status', ['pending', 'not_active', 'active'])->default('active'); // pending, not_active, active

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calvings');
    }
};
