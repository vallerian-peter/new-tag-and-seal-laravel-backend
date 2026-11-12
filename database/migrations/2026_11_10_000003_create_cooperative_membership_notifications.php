<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cooperative_membership_notifications');
        Schema::create('cooperative_membership_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_uuid')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['membership_uuid', 'event'], 'coop_mem_notif_uuid_event_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_membership_notifications');
    }
};
