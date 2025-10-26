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
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmerId')->constrained('farmers')->onDelete('cascade');
            $table->string('uuid')->unique();
            $table->string('referenceNo')->unique();
            $table->string('regionalRegNo')->nullable();
            $table->string('name');
            $table->integer('size');
            $table->enum('sizeUnit', ['acre', 'hectare', 'square_meter', 'square_kilometer'])->default('acre');
            $table->string('latitudes')->nullable();
            $table->string('longitudes')->nullable();
            $table->string('physicalAddress');
            $table->foreignId('villageId')->nullable()->constrained('villages')->onDelete('set null');
            $table->foreignId('wardId')->nullable()->constrained('wards')->onDelete('set null');
            $table->foreignId('districtId')->nullable()->constrained('districts')->onDelete('set null');
            $table->foreignId('regionId')->nullable()->constrained('regions')->onDelete('set null');
            $table->foreignId('countryId')->default(1)->constrained('countries')->onDelete('cascade');
            $table->foreignId('legalStatusId')->nullable()->constrained('legal_statuses')->onDelete('set null');
            $table->enum('status', ['active', 'not-active'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};
