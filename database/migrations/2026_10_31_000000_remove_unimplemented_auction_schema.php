<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasReservations = Schema::hasTable('animal_commerce_reservations');
        $hasOrders = Schema::hasTable('purchase_orders');
        $hasAuctionLotColumn = $hasOrders && Schema::hasColumn('purchase_orders', 'auction_lot_uuid');

        // Do not discard auction data on an installation that has begun using it.
        if ($hasReservations && DB::table('animal_commerce_reservations')
            ->where('reservation_type', 'live_auction_lot')->exists()) {
            throw new RuntimeException('Auction reservations exist; reconcile them before removing the unused schema.');
        }

        if ($hasOrders) {
            $auctionOrders = DB::table('purchase_orders')->where('source_type', 'live_auction');
            if ($hasAuctionLotColumn) {
                $auctionOrders->orWhereNotNull('auction_lot_uuid');
            }
            if ($auctionOrders->exists()) {
                throw new RuntimeException('Auction orders exist; reconcile them before removing the unused schema.');
            }
        }

        if ($hasReservations && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `animal_commerce_reservations` MODIFY COLUMN `reservation_type` ENUM('marketplace_listing', 'offer_hold', 'order_commitment') NOT NULL");
        }

        if ($hasOrders) {
            if ($hasAuctionLotColumn) {
                Schema::table('purchase_orders', function (Blueprint $table): void {
                    $table->dropColumn('auction_lot_uuid');
                });
            }
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `purchase_orders` MODIFY COLUMN `source_type` ENUM('marketplace_direct', 'marketplace_negotiated') NOT NULL");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('animal_commerce_reservations') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `animal_commerce_reservations` MODIFY COLUMN `reservation_type` ENUM('marketplace_listing', 'offer_hold', 'order_commitment', 'live_auction_lot') NOT NULL");
        }

        if (Schema::hasTable('purchase_orders')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `purchase_orders` MODIFY COLUMN `source_type` ENUM('marketplace_direct', 'marketplace_negotiated', 'live_auction') NOT NULL");
            }
            if (! Schema::hasColumn('purchase_orders', 'auction_lot_uuid')) {
                Schema::table('purchase_orders', function (Blueprint $table): void {
                    $table->uuid('auction_lot_uuid')->nullable()->index();
                });
            }
        }
    }
};
