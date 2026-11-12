<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A factory receipt closes one trip: what was dispatched, what arrived, what
 * the factory accepted, and the difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_factory_deliveries', function (Blueprint $table) {
            $table->uuid('dispatch_order_uuid')->nullable()->change();
            if (! Schema::hasColumn('milk_factory_deliveries', 'trip_uuid')) {
                $table->string('trip_uuid', 64)->nullable()->unique()->after('dispatch_order_uuid');
            }
            if (! Schema::hasColumn('milk_factory_deliveries', 'factory_uuid')) {
                $table->string('factory_uuid', 64)->nullable()->index()->after('trip_uuid');
            }
            if (! Schema::hasColumn('milk_factory_deliveries', 'dispatched_volume_liters')) {
                $table->decimal('dispatched_volume_liters', 10, 2)->default(0)->after('factory_qa_officer_user_id');
            }
            if (! Schema::hasColumn('milk_factory_deliveries', 'accepted_volume_liters')) {
                $table->decimal('accepted_volume_liters', 10, 2)->default(0)->after('received_volume_liters');
            }
            if (! Schema::hasColumn('milk_factory_deliveries', 'notes')) {
                $table->text('notes')->nullable()->after('spillage_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('milk_factory_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('milk_factory_deliveries', 'trip_uuid')) {
                $table->dropUnique(['trip_uuid']);
            }
            if (Schema::hasColumn('milk_factory_deliveries', 'factory_uuid')) {
                $table->dropIndex(['factory_uuid']);
            }
            foreach (['trip_uuid', 'factory_uuid', 'dispatched_volume_liters', 'accepted_volume_liters', 'notes'] as $column) {
                if (Schema::hasColumn('milk_factory_deliveries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
