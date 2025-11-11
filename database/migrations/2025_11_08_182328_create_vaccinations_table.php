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
        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('vaccinationNo')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->foreignId('vaccineId')->constrained('vaccines')->cascadeOnDelete();
            $table->foreignId('diseaseId')->constrained('diseases')->cascadeOnDelete();
            $table->string('vetId')->nullable();
            $table->string('extensionOfficerId')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
    }
};
