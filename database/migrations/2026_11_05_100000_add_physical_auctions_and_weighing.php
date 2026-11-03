<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical (in-person) auctions and weighing, ported from the Shamba Bora
 * instant-auction app:
 *  - a session is either `live` (video + online bidding) or `physical`
 *    (market day: weigh on the scale, record the buyer and price, print a
 *    receipt);
 *  - a lot remembers the weight captured on the Bluetooth scale, whether the
 *    reading was stable, and which device produced it;
 *  - a sale may belong to a walk-in buyer with no account, carries the
 *    receipt number, the weight and the price per kilogram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_sessions', function (Blueprint $table) {
            $table->enum('mode', ['live', 'physical'])->default('live')->after('status');
        });

        Schema::table('auction_lots', function (Blueprint $table) {
            $table->decimal('weightKg', 8, 2)->nullable()->after('photos');
            $table->string('weightUnit', 8)->nullable()->after('weightKg');
            $table->boolean('weightStable')->default(false)->after('weightUnit');
            $table->enum('weightSource', ['scale', 'manual'])->nullable()->after('weightStable');
            $table->string('weightDeviceName', 100)->nullable()->after('weightSource');
            $table->timestamp('weighedAt')->nullable()->after('weightDeviceName');
            $table->unsignedBigInteger('weighedBy')->nullable()->after('weighedAt');
        });

        Schema::table('auction_sales', function (Blueprint $table) {
            $table->dropForeign(['buyerUserId']);
        });
        Schema::table('auction_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('buyerUserId')->nullable()->change();
            $table->foreign('buyerUserId')->references('id')->on('users')->nullOnDelete();
            $table->string('receiptNo', 40)->nullable()->unique()->after('uuid');
            $table->enum('channel', ['live', 'physical'])->default('live')->after('status');
            $table->string('buyerName', 150)->nullable()->after('buyerUserId');
            $table->string('buyerPhone', 30)->nullable()->after('buyerName');
            $table->decimal('weightKg', 8, 2)->nullable()->after('amount');
            $table->decimal('pricePerKg', 14, 2)->nullable()->after('weightKg');
            $table->unsignedBigInteger('recordedByUserId')->nullable()->after('confirmedByUserId');
        });
    }

    public function down(): void
    {
        Schema::table('auction_sales', function (Blueprint $table) {
            $table->dropUnique(['receiptNo']);
            $table->dropColumn(['receiptNo', 'channel', 'buyerName', 'buyerPhone', 'weightKg', 'pricePerKg', 'recordedByUserId']);
        });
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropColumn(['weightKg', 'weightUnit', 'weightStable', 'weightSource', 'weightDeviceName', 'weighedAt', 'weighedBy']);
        });
        Schema::table('auction_sessions', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
