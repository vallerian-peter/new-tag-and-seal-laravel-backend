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
            $table->string('referenceNo')->unique();
            $table->string('medicalLicenseNo')->nullable();
            $table->string('fullName');
            $table->string('phoneNumber');
            $table->string('email')->unique();
            $table->string('address')->nullable();
            $table->foreignId('countryId')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('regionId')->constrained('regions')->cascadeOnDelete();
            $table->foreignId('districtId')->constrained('districts')->cascadeOnDelete();
            $table->enum('gender', ['male', 'female']);
            $table->date('dateOfBirth');
            $table->foreignId('identityCardTypeId')->nullable()->constrained('identity_card_types')->cascadeOnDelete();
            $table->foreignId('schoolLevelId')->nullable()->constrained('school_levels')->cascadeOnDelete();
            $table->string('identityNo')->nullable();
            $table->enum('status', ['active', 'notActive']);
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
