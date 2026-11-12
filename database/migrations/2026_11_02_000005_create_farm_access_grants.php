<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('farm_uuid', 36);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['farm_uuid', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_access_grants');
    }
};
