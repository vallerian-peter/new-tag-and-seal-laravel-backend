<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperative_membership_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_uuid')->index();
            $table->foreignId('applicant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('amcos_uuid')->index();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_membership_reviews');
    }
};
