<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['owner_user_id', 'member_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_access_grants');
    }
};
