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
        Schema::create('livestocks', function (Blueprint $table) {
            $table->id();
            $table->string('farmUuid');  // Farm UUID reference (stores farm.uuid)
            $table->index('farmUuid');  // Index for faster lookups
            $table->string('uuid')->unique();
            $table->string('identificationNumber')->unique();
            $table->string('dummyTagId')->nullable();
            $table->string('barcodeTagId')->nullable();
            $table->string('rfidTagId')->nullable();
            $table->foreignId('livestockTypeId')->constrained('livestock_types')->onDelete('cascade');
            $table->string('name');
            $table->date('dateOfBirth');
            $table->string('motherUuid')->nullable();  // Mother livestock UUID reference
            $table->string('fatherUuid')->nullable();  // Father livestock UUID reference
            $table->index('motherUuid');  // Index for faster lookups
            $table->index('fatherUuid');  // Index for faster lookups
            $table->enum('gender', ['male', 'female']);
            $table->foreignId('breedId')->constrained('breeds')->onDelete('cascade');
            $table->foreignId('speciesId')->constrained('species')->onDelete('cascade');
            $table->enum('status', ['active', 'notActive'])->default('active');
            $table->foreignId('livestockObtainedMethodId')->nullable()->constrained('livestock_obtained_methods')->onDelete('set null');
            $table->date('dateFirstEnteredToFarm')->nullable();
            $table->string('weightAsOnRegistration')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livestocks');
    }
};
