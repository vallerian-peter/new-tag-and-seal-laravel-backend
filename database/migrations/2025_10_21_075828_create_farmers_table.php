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
        Schema::create('farmers', function (Blueprint $table) {
            $table->id();
            $table->string('farmerNo')->unique();
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('surname');
            $table->string('phone1');
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->string('physicalAddress')->nullable();
            $table->string('farmerOrganizationMembership')->nullable();
            $table->date('dateOfBirth')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->foreignId('identityCardTypeId')->nullable()->constrained('identity_card_types')->onDelete('set null');
            $table->string('identityNumber')->nullable();
            $table->string('street')->nullable();
            $table->foreignId('schoolLevelId')->nullable()->constrained('school_levels')->onDelete('set null');
            $table->foreignId('villageId')->nullable()->constrained('villages')->onDelete('set null');
            $table->foreignId('wardId')->nullable()->constrained('wards')->onDelete('set null');
            $table->foreignId('districtId')->nullable()->constrained('districts')->onDelete('set null');
            $table->foreignId('regionId')->nullable()->constrained('regions')->onDelete('set null');
            $table->foreignId('countryId')->default(1)->constrained('countries')->onDelete('cascade');
            $table->enum('farmerType', ['individual', 'organization']);
            $table->foreignId('createdBy')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('status', ['active', 'notActive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farmers');
    }
};
