<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->timestamps();
        });
        Schema::create('feature_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature', 32);
            $table->foreignId('organization_id')->nullable()->constrained('feature_organizations')->restrictOnDelete();
            $table->json('details');
            $table->timestamps();
            $table->unique(['user_id', 'feature']);
        });
        Schema::create('career_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->json('details');
            $table->date('closes_at')->nullable();
            $table->boolean('closed')->default(false);
            $table->timestamps();
        });
        Schema::create('career_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('career_jobs')->cascadeOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('applicant_snapshot');
            $table->text('cover_letter')->nullable();
            $table->string('status')->default('submitted');
            $table->timestamps();
            $table->unique(['job_id', 'applicant_user_id']);
        });
        Schema::create('career_saved_jobs', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('career_jobs')->cascadeOnDelete();
            $table->primary(['user_id', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_saved_jobs');
        Schema::dropIfExists('career_applications');
        Schema::dropIfExists('career_jobs');
        Schema::dropIfExists('feature_memberships');
        Schema::dropIfExists('feature_organizations');
    }
};
