<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animal_subjects', function (Blueprint $table): void {
            $table->json('registration_context_json')->nullable();
        });
        Schema::create('dynamic_mutation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_uuid')->unique();
            $table->uuid('subject_uuid')->index();
            $table->uuid('actor_uuid');
            $table->string('fingerprint', 64);
            $table->json('response_json');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_mutation_receipts');
        Schema::table('animal_subjects', fn (Blueprint $table) => $table->dropColumn('registration_context_json'));
    }
};
