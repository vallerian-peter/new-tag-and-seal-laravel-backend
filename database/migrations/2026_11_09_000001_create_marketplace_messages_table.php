<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_messages')) {
            Schema::create('marketplace_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('thread_key', 190)->index();
                $table->enum('category', ['order', 'offer', 'inquiry'])->default('inquiry')->index();
                $table->uuid('listing_uuid')->nullable()->index();
                $table->uuid('order_uuid')->nullable()->index();
                $table->uuid('offer_uuid')->nullable()->index();
                $table->uuid('livestock_uuid')->nullable()->index();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('topic', 255)->nullable();
                $table->text('message');
                $table->uuid('reply_to_uuid')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->dateTime('sms_notified_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_messages');
    }
};

