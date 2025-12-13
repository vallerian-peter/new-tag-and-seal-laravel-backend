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
        // Drop foreign keys first
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->dropForeign(['identityCardTypeId']);
            $table->dropForeign(['schoolLevelId']);
        });

        // Drop old columns
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->dropColumn([
                'referenceNo',
                'medicalLicenseNo',
                'fullName',
                'phoneNumber',
                'dateOfBirth',
                'identityCardTypeId',
                'identityNo',
                'schoolLevelId',
                'status',
            ]);
        });

        // Add new columns
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->string('firstName')->after('id');
            $table->string('middleName')->nullable()->after('firstName');
            $table->string('lastName')->after('middleName');
            $table->string('phone')->after('email');
            $table->string('password')->after('phone');
            $table->string('licenseNumber')->nullable()->after('gender');
            $table->foreignId('wardId')->nullable()->constrained('wards')->cascadeOnDelete()->after('districtId');
            $table->string('organization')->nullable()->after('wardId');
            $table->boolean('isVerified')->default(false)->after('organization');
            $table->string('specialization')->nullable()->after('isVerified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop new columns
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->dropForeign(['wardId']);
            $table->dropColumn([
                'firstName',
                'middleName',
                'lastName',
                'phone',
                'password',
                'licenseNumber',
                'wardId',
                'organization',
                'isVerified',
                'specialization',
            ]);
        });

        // Restore old columns
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->string('referenceNo')->unique()->after('id');
            $table->string('medicalLicenseNo')->nullable()->after('referenceNo');
            $table->string('fullName')->after('medicalLicenseNo');
            $table->string('phoneNumber')->after('email');
            $table->date('dateOfBirth')->after('gender');
            $table->foreignId('identityCardTypeId')->nullable()->constrained('identity_card_types')->cascadeOnDelete()->after('dateOfBirth');
            $table->string('identityNo')->nullable()->after('identityCardTypeId');
            $table->foreignId('schoolLevelId')->nullable()->constrained('school_levels')->cascadeOnDelete()->after('identityNo');
            $table->enum('status', ['active', 'notActive'])->after('schoolLevelId');
        });
    }
};
