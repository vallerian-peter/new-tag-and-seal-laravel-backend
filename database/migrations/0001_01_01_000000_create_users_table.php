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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->integer('roleId');
            $table->enum('status', ['active', 'notActive'])->default('active');
            $table->foreignId('createdBy')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('updatedBy')->references('id')->on('users')->onDelete('cascade')->nullable();
            $table->string('remember_token', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('userId')->nullable()->index();
            $table->string('ipAddress', 45)->nullable();
            $table->text('userAgent')->nullable();
            $table->longText('payload');
            $table->integer('lastActivity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
