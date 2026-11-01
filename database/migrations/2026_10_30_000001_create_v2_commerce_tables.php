<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Animal Commerce Reservations (Decouples commercial hold from biological life status)
        Schema::create('animal_commerce_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('livestock_uuid')->index();
            $table->enum('reservation_type', ['marketplace_listing', 'offer_hold', 'order_commitment']);
            $table->uuid('reference_uuid')->index();
            $table->enum('status', ['active', 'fulfilled', 'released', 'expired'])->default('active');
            $table->foreignId('held_by_user_id')->constrained('users');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->index(['livestock_uuid', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // 2. Marketplace Listings
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('seller_user_id')->constrained('users');
            $table->uuid('farm_uuid')->index();
            $table->string('listing_title');
            $table->enum('listing_type', ['individual', 'group_lot'])->default('individual');
            $table->decimal('total_asking_price', 14, 2);
            $table->boolean('is_negotiable')->default(true);
            $table->decimal('minimum_acceptable_price', 14, 2)->nullable();
            $table->char('currency', 3)->default('TZS');
            $table->enum('status', ['draft', 'active', 'under_negotiation', 'reserved', 'sold', 'withdrawn', 'expired'])->default('draft');
            $table->boolean('share_health_records')->default(false); // Default-deny privacy invariant
            $table->json('approved_health_categories')->nullable();
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'total_asking_price']);
            $table->index('seller_user_id');
        });

        // 3. Marketplace Listing Items (Normalized mapping supporting individual animals in lots)
        Schema::create('marketplace_listing_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('listing_uuid')->index();
            $table->uuid('livestock_uuid')->index();
            $table->decimal('allocated_asking_price', 14, 2);
            $table->boolean('is_sold')->default(false);
            $table->timestamps();

            $table->unique(['listing_uuid', 'livestock_uuid']);
        });

        // 4. Marketplace Offers & Counter-Offers
        Schema::create('marketplace_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('listing_uuid')->index();
            $table->foreignId('buyer_user_id')->constrained('users');
            $table->decimal('offered_amount', 14, 2);
            $table->decimal('counter_amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('TZS');
            $table->enum('status', ['proposed', 'countered', 'accepted', 'rejected', 'expired', 'cancelled'])->default('proposed');
            $table->text('buyer_notes')->nullable();
            $table->text('seller_notes')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['listing_uuid', 'status']);
            $table->index('buyer_user_id');
        });

        // 5. Purchase Orders (Seller-scoped orders for multi-item checkout)
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_code', 50)->unique();
            $table->foreignId('seller_user_id')->constrained('users');
            $table->foreignId('buyer_user_id')->constrained('users');
            $table->uuid('origin_farm_uuid')->index();
            $table->uuid('destination_farm_uuid')->nullable()->index();
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3)->default('TZS');
            $table->enum('source_type', ['marketplace_direct', 'marketplace_negotiated']);
            $table->uuid('offer_uuid')->nullable()->index();
            $table->enum('status', ['pending_settlement', 'ready_for_handover', 'handed_over', 'transferred', 'cancelled', 'disputed'])->default('pending_settlement');
            $table->enum('settlement_method', ['cash_on_delivery', 'direct_mobile_money', 'bank_transfer', 'escrow'])->default('direct_mobile_money');
            $table->dateTime('settlement_verified_at')->nullable();
            $table->foreignId('settlement_verified_by_user_id')->nullable()->constrained('users');
            $table->string('handover_challenge_hash', 64);
            $table->dateTime('handover_challenge_expires_at')->nullable();
            $table->dateTime('handover_verified_at')->nullable();
            $table->dateTime('ownership_transferred_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['seller_user_id', 'buyer_user_id']);
        });

        // 6. Purchase Order Items
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('order_uuid')->index();
            $table->uuid('livestock_uuid')->index();
            $table->decimal('agreed_price', 14, 2);
            $table->string('scanned_tag_at_handover', 100)->nullable();
            $table->enum('handover_status', ['pending', 'verified', 'rejected_discrepancy'])->default('pending');
            $table->uuid('event_record_uuid')->nullable()->index();
            $table->timestamps();

            $table->unique(['order_uuid', 'livestock_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('marketplace_offers');
        Schema::dropIfExists('marketplace_listing_items');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('animal_commerce_reservations');
    }
};
