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
        Schema::create('vaccines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('farmUuid')->nullable();
            $table->string('name');
            $table->string('lot')->nullable();
            $table->enum('formulationType', ['live-attenuated', 'inactivated'])->nullable();
            $table->string('dose')->nullable();
            $table->enum('status', ['active', 'inactive', 'expired'])->nullable();
            $table->foreignId('vaccineTypeId')->constrained('vaccine_types')->onDelete('cascade');
            $table->string('vaccineSchedule')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vaccines');
    }
};

