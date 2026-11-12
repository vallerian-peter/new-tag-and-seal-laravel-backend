<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live livestock auctions ("Mnada wa Mifugo Mubashara").
 *
 * A session is a live video room (LiveKit) with ordered lots. Each lot is one
 * tagged animal. Bids are persisted for history; the marketplace lock lives in
 * `livestock_sale_holds` so one animal can never be sold through two channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestock_sale_holds', function (Blueprint $table) {
            $table->id();
            $table->string('livestockUuid', 100)->index();
            // Only one ACTIVE hold per animal: activeKey = livestockUuid while active, NULL otherwise.
            $table->string('activeKey', 100)->nullable()->unique();
            $table->enum('channel', ['auction', 'marketplace']);
            $table->string('referenceUuid', 100)->index();
            $table->foreignId('holderUserId')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['active', 'released', 'consumed'])->default('active');
            $table->timestamp('releasedAt')->nullable();
            $table->timestamps();
        });

        Schema::create('auction_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('hostUserId')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('coverPath')->nullable();
            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');
            $table->timestamp('scheduledAt')->nullable();
            $table->timestamp('startedAt')->nullable();
            $table->timestamp('endedAt')->nullable();
            $table->timestamp('lastHeartbeatAt')->nullable();
            $table->string('currency', 8)->default('TZS');
            $table->json('settings')->nullable();
            $table->text('paymentInstructions')->nullable();
            $table->unsignedInteger('viewers')->default(0);
            $table->unsignedInteger('viewerPeak')->default(0);
            $table->unsignedInteger('lotsCount')->default(0);
            $table->unsignedInteger('soldCount')->default(0);
            $table->decimal('grossSales', 16, 2)->default(0);
            $table->string('egressId')->nullable();
            $table->enum('recordingStatus', ['none', 'recording', 'processing', 'available', 'failed'])->default('none');
            $table->string('recordingPath')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduledAt']);
            $table->index(['hostUserId', 'status']);
        });

        Schema::create('auction_lots', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('sessionId')->constrained('auction_sessions')->cascadeOnDelete();
            $table->string('livestockUuid', 100)->index();
            $table->string('farmUuid', 100)->index();
            $table->json('livestockSnapshot')->nullable();
            $table->unsignedSmallInteger('lotNumber');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->json('photos')->nullable();
            $table->decimal('startingPrice', 14, 2);
            $table->decimal('minIncrement', 14, 2);
            $table->decimal('reservePrice', 14, 2)->nullable();
            $table->unsignedSmallInteger('durationSeconds')->default(120);
            $table->enum('status', ['queued', 'live', 'sold', 'unsold', 'cancelled'])->default('queued');
            $table->timestamp('openedAt')->nullable();
            $table->timestamp('closesAt')->nullable();
            $table->timestamp('closedAt')->nullable();
            $table->unsignedTinyInteger('extendedCount')->default(0);
            $table->decimal('currentPrice', 14, 2)->default(0);
            $table->unsignedBigInteger('currentBidId')->nullable();
            $table->unsignedInteger('bidCount')->default(0);
            $table->foreignId('winnerUserId')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('winningBidId')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['sessionId', 'lotNumber']);
            $table->index(['sessionId', 'status']);
            $table->index('closesAt');
        });

        Schema::create('auction_bids', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('lotId')->constrained('auction_lots')->cascadeOnDelete();
            $table->foreignId('sessionId')->constrained('auction_sessions')->cascadeOnDelete();
            $table->foreignId('bidderUserId')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('bidderNumber');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['leading', 'outbid', 'won', 'lost', 'rejected'])->default('leading');
            $table->string('rejectReason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lotId', 'amount']);
            $table->index(['bidderUserId', 'created_at']);
        });

        Schema::create('auction_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sessionId')->constrained('auction_sessions')->cascadeOnDelete();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('bidderNumber');
            $table->enum('role', ['host', 'buyer'])->default('buyer');
            $table->timestamp('joinedAt')->nullable();
            $table->timestamp('lastSeenAt')->nullable();
            $table->timestamps();

            $table->unique(['sessionId', 'userId']);
            $table->unique(['sessionId', 'bidderNumber']);
            $table->index(['sessionId', 'lastSeenAt']);
        });

        Schema::create('auction_sales', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('lotId')->unique()->constrained('auction_lots')->cascadeOnDelete();
            $table->foreignId('sessionId')->constrained('auction_sessions')->cascadeOnDelete();
            $table->foreignId('buyerUserId')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sellerUserId')->constrained('users')->cascadeOnDelete();
            $table->string('livestockUuid', 100)->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TZS');
            $table->enum('status', ['awaiting_payment', 'paid', 'cancelled', 'expired'])->default('awaiting_payment');
            $table->text('paymentInstructions')->nullable();
            $table->string('paymentReference')->nullable();
            $table->timestamp('paymentDeadlineAt')->nullable();
            $table->timestamp('paidAt')->nullable();
            $table->foreignId('confirmedByUserId')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyerUserId', 'status']);
            $table->index(['sellerUserId', 'status']);
        });

        Schema::create('auction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sessionId')->constrained('auction_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('lotId')->nullable()->index();
            $table->string('type', 40);
            $table->foreignId('actorUserId')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sessionId', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_events');
        Schema::dropIfExists('auction_sales');
        Schema::dropIfExists('auction_participants');
        Schema::dropIfExists('auction_bids');
        Schema::dropIfExists('auction_lots');
        Schema::dropIfExists('auction_sessions');
        Schema::dropIfExists('livestock_sale_holds');
    }
};
