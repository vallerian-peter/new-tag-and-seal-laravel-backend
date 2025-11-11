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
        Schema::create('dewormings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            $table->foreignId('administrationRouteId')
                ->constrained('administration_routes')
                ->cascadeOnDelete();

            $table->foreignId('medicineId')
                ->constrained('medicines')
                ->cascadeOnDelete();

            $table->string('vetId', 191)->nullable()->index();
            $table->string('extensionOfficerId', 191)->nullable()->index();

            $table->string('quantity');
            $table->string('dose')->nullable();
            $table->string('nextAdministrationDate')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dewormings');
    }
};
