<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The auctioneer has the final word: a lot that ends with bids waits in
 * `awaiting_decision` until the auctioneer marks it sold or not sold. The
 * decision, who made it and when are kept for the auction history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auction_lots MODIFY status ENUM('queued','live','awaiting_decision','sold','unsold','cancelled') NOT NULL DEFAULT 'queued'");
        }
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->string('decision', 20)->nullable()->after('winningBidId');
            $table->string('decisionNote', 500)->nullable()->after('decision');
            $table->unsignedBigInteger('decidedBy')->nullable()->after('decisionNote');
            $table->timestamp('decidedAt')->nullable()->after('decidedBy');
            $table->timestamp('biddingEndedAt')->nullable()->after('closedAt');
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropColumn(['decision', 'decisionNote', 'decidedBy', 'decidedAt', 'biddingEndedAt']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::table('auction_lots')->where('status', 'awaiting_decision')->update(['status' => 'unsold']);
            DB::statement("ALTER TABLE auction_lots MODIFY status ENUM('queued','live','sold','unsold','cancelled') NOT NULL DEFAULT 'queued'");
        }
    }
};
