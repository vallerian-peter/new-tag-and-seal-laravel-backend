<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auction centres ("vituo vya mnada"): the physical livestock markets where
 * authorised auctioneers run live sessions. A session hosted at a centre may
 * consign any tagged animal a farmer brings in, so lots carry their own
 * seller instead of inheriting the host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_centres', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Location lookups are soft references: the lookup tables predate
            // this module and are managed by the admin portal.
            $table->unsignedBigInteger('regionId')->nullable()->index();
            $table->unsignedBigInteger('districtId')->nullable()->index();
            $table->unsignedBigInteger('wardId')->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('createdBy')->nullable();
            $table->timestamps();

            $table->index(['status', 'name']);
        });

        Schema::create('auction_centre_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centreId')->constrained('auction_centres')->cascadeOnDelete();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['manager', 'auctioneer', 'clerk'])->default('auctioneer');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('assignedBy')->nullable();
            $table->timestamps();

            $table->unique(['centreId', 'userId']);
            $table->index(['userId', 'status']);
        });

        Schema::table('auction_sessions', function (Blueprint $table) {
            $table->foreignId('centreId')->nullable()->after('hostUserId')
                ->constrained('auction_centres')->nullOnDelete();
            $table->index(['centreId', 'status']);
        });

        Schema::table('auction_lots', function (Blueprint $table) {
            // Who gets paid for this lot. Null means the session host.
            $table->foreignId('sellerUserId')->nullable()->after('farmUuid')
                ->constrained('users')->nullOnDelete();
            $table->json('sellerCard')->nullable()->after('sellerUserId');
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sellerUserId');
            $table->dropColumn('sellerCard');
        });
        Schema::table('auction_sessions', function (Blueprint $table) {
            $table->dropIndex(['centreId', 'status']);
            $table->dropConstrainedForeignId('centreId');
        });
        Schema::dropIfExists('auction_centre_members');
        Schema::dropIfExists('auction_centres');
    }
};
