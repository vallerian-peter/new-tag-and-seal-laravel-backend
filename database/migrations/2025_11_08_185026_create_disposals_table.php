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
        Schema::create('disposals', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->foreignId('disposalTypeId')->constrained('disposal_types')->cascadeOnDelete();
            $table->text('reasons');
            $table->text('remarks');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disposals');
    }
};
