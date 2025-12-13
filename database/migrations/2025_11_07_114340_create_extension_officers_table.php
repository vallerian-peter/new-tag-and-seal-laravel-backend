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
        Schema::create('extension_officers', function (Blueprint $table) {
            $table->id();
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('password');
            $table->enum('gender', ['male', 'female']);
            $table->string('licenseNumber')->nullable();
            $table->string('address')->nullable();
            $table->foreignId('countryId')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('regionId')->constrained('regions')->cascadeOnDelete();
            $table->foreignId('districtId')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('wardId')->constrained('wards')->cascadeOnDelete();
            $table->string('organization')->nullable();
            $table->boolean('isVerified')->default(false);
            $table->string('specialization')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extension_officers');
    }
};
