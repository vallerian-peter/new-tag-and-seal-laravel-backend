<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auction officers (auctioneers). An officer may register from the app and
 * must be verified by an administrator before conducting any auction; the
 * portal can also create officers directly. Centre memberships gain a
 * `pending` state for self-registrations awaiting approval, and sessions
 * record who planned them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_officers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('userId')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('officerNo', 30)->unique();
            $table->string('firstName', 100);
            $table->string('middleName', 100)->nullable();
            $table->string('lastName', 100);
            $table->string('phone', 30);
            $table->string('email', 255)->nullable();
            $table->string('nationalId', 50)->nullable();
            $table->string('licenceNo', 100)->nullable();
            $table->string('licenceDocumentPath', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('regionId')->nullable();
            $table->unsignedBigInteger('districtId')->nullable();
            $table->unsignedBigInteger('requestedCentreId')->nullable()->index();
            $table->text('bio')->nullable();
            $table->enum('verificationStatus', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('verificationNotes')->nullable();
            $table->unsignedBigInteger('verifiedBy')->nullable();
            $table->timestamp('verifiedAt')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('createdBy')->nullable();
            $table->timestamps();

            $table->index(['verificationStatus', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE auction_centre_members MODIFY status ENUM('pending','active','inactive') NOT NULL DEFAULT 'active'");
        }

        Schema::table('auction_sessions', function (Blueprint $table) {
            // Set when the session was planned from the management portal.
            $table->unsignedBigInteger('createdBy')->nullable()->after('centreId');
        });
    }

    public function down(): void
    {
        Schema::table('auction_sessions', function (Blueprint $table) {
            $table->dropColumn('createdBy');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::table('auction_centre_members')->where('status', 'pending')->update(['status' => 'inactive']);
            DB::statement("ALTER TABLE auction_centre_members MODIFY status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
        }
        Schema::dropIfExists('auction_officers');
    }
};
