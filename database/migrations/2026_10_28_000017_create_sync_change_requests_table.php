<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Portal records which synced entity it changed, in the same transaction as the change;
 * the backend, the only writer of the change feed, appends the entry before serving the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->uuid('entity_uuid');
            $table->string('action', 10)->default('upsert');
            $table->unsignedInteger('revision')->nullable();
            $table->timestamp('recorded_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_change_requests');
    }
};
