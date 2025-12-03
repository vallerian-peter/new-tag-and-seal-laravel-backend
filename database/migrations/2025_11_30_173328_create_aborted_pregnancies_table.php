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
        Schema::create('aborted_pregnancies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->date('abortionDate');
            $table->foreignId('reproductiveProblemId')->nullable()->constrained('reproductive_problems')->cascadeOnDelete();
            $table->text('remarks')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aborted_pregnancies');
    }
};

