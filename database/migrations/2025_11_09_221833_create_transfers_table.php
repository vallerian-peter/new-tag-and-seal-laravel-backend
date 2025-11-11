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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->string('toFarmUuid');
            $table->index('toFarmUuid');

            $table->string('transporterId')->nullable();

            $table->string('reason')->nullable();
            $table->string('price')->nullable();
            $table->string('transferDate')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
