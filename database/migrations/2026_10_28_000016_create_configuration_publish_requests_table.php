<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Portal records a request in the same transaction as the configuration change;
 * the backend, the only manifest compiler, fulfils it before serving a manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_publish_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('requested_by_uuid')->nullable();
            $table->string('reason', 120);
            $table->unsignedBigInteger('revision')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_publish_requests');
    }
};
