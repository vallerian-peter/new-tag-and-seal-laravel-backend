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
        // 1. Extend Marketplace Listings with group stock, quantity & bulk pricing tiers
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->enum('group_sale_type', ['whole_lot', 'quantity_based'])->nullable()->after('listing_type');
            $table->uuid('blueprint_group_uuid')->nullable()->after('group_sale_type')->index();
            $table->unsignedInteger('total_quantity')->default(1)->after('blueprint_group_uuid');
            $table->unsignedInteger('available_quantity')->default(1)->after('total_quantity');
            $table->unsignedInteger('reserved_quantity')->default(0)->after('available_quantity');
            $table->unsignedInteger('sold_quantity')->default(0)->after('reserved_quantity');
            $table->decimal('unit_price', 14, 2)->nullable()->after('total_asking_price');
            $table->unsignedInteger('bulk_threshold')->nullable()->after('unit_price');
            $table->decimal('bulk_price', 14, 2)->nullable()->after('bulk_threshold');
            $table->unsignedInteger('min_order_quantity')->default(1)->after('bulk_price');
            $table->unsignedInteger('max_order_quantity')->nullable()->after('min_order_quantity');
            $table->string('location_name')->nullable()->after('photos');
        });

        // 2. Extend Purchase Orders with 48h reservation, payment evidence, and dispute/cancellation lifecycle
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dateTime('reserved_until')->nullable()->after('status')->index();
            $table->string('payment_evidence_ref', 100)->nullable()->after('settlement_verified_by_user_id');
            $table->text('payment_evidence_notes')->nullable()->after('payment_evidence_ref');
            $table->string('payment_evidence_receipt', 255)->nullable()->after('payment_evidence_notes');
            $table->dateTime('payment_evidence_submitted_at')->nullable()->after('payment_evidence_receipt');
            $table->text('cancellation_reason')->nullable()->after('payment_evidence_submitted_at');
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->after('cancellation_reason');
            $table->text('dispute_reason')->nullable()->after('cancelled_by_user_id');
            $table->dateTime('disputed_at')->nullable()->after('dispute_reason');
            $table->dateTime('sms_notified_at')->nullable()->after('disputed_at');
        });

        // 3. Extend Purchase Order Items for quantity, unit price & group stock
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->uuid('livestock_uuid')->nullable()->change();
            $table->unsignedInteger('quantity')->default(1)->after('livestock_uuid');
            $table->decimal('unit_price', 14, 2)->nullable()->after('quantity');
            $table->boolean('is_bulk_applied')->default(false)->after('agreed_price');
            $table->uuid('blueprint_group_uuid')->nullable()->after('is_bulk_applied')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'quantity',
                'unit_price',
                'is_bulk_applied',
                'blueprint_group_uuid',
            ]);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn([
                'reserved_until',
                'payment_evidence_ref',
                'payment_evidence_notes',
                'payment_evidence_receipt',
                'payment_evidence_submitted_at',
                'cancellation_reason',
                'cancelled_by_user_id',
                'dispute_reason',
                'disputed_at',
                'sms_notified_at',
            ]);
        });

        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropColumn([
                'group_sale_type',
                'blueprint_group_uuid',
                'total_quantity',
                'available_quantity',
                'reserved_quantity',
                'sold_quantity',
                'unit_price',
                'bulk_threshold',
                'bulk_price',
                'min_order_quantity',
                'max_order_quantity',
                'location_name',
            ]);
        });
    }
};

