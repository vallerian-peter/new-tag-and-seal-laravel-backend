<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperative_user_memberships', function (Blueprint $table) {
            $table->string('applicant_name')->nullable();
            $table->string('applicant_gender', 20)->nullable();
            $table->date('applicant_date_of_birth')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cooperative_user_memberships', function (Blueprint $table) {
            $table->dropColumn(['applicant_name', 'applicant_gender', 'applicant_date_of_birth']);
        });
    }
};
