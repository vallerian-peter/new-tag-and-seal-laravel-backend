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
        Schema::create('farm_users', function (Blueprint $table) {
            $table->id();

            $table->string('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->enum('roleTitle', [
                'farm-manager',
                'feeding-user',
                'weight-change-user',
                'deworming-user',
                'medication-user',
                'vaccination-user',
                'disposal-user',
                'birth-event-user',
                'aborted-pregnancy-user',
                'dryoff-user',
                'insemination-user',
                'pregnancy-user',
                'milking-user',
                'transfer-user',
            ])->nullable();

            $table->enum('gender', ['male', 'female']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farm_users');
    }
};
