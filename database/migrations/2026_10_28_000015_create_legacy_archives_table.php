<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_archives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->index();
            $table->string('family', 40)->index();
            $table->string('source_table', 80)->index();
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('max_id')->nullable();
            $table->string('path');
            $table->char('sha256', 64);
            $table->uuid('actor_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_archives');
    }
};
