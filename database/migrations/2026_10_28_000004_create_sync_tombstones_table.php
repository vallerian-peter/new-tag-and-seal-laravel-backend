<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 100);
            $table->uuid('entity_uuid');
            $table->uuid('farm_uuid')->nullable()->index();
            $table->unsignedBigInteger('entity_revision');
            $table->string('operation', 30)->default('deleted');
            $table->timestamp('occurred_at');
            $table->timestamp('superseded_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_uuid']);
            $table->index(['entity_type', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tombstones');
    }
};
