<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('phone', 16)->unique();
            $table->string('display_name')->nullable();
            $table->string('pin_hash');
            $table->timestamps();
        });

        Schema::create('phone_auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 16)->unique();
            $table->string('purpose', 16);
            $table->string('code_hash');
            $table->string('proof_hash')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('phone_auth_challenges');
        Schema::dropIfExists('phone_identities');
    }
};
