<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch status lifecycle: dispatched -> on_trip -> delivered -> reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperative_dispatches', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_dispatches', 'batch_number')) {
                $table->string('batch_number', 60)->nullable()->after('dispatch_code');
            }
            if (! Schema::hasColumn('cooperative_dispatches', 'loaded_at')) {
                $table->timestamp('loaded_at')->nullable()->after('dispatch_temperature_celsius');
            }
            if (! Schema::hasColumn('cooperative_dispatches', 'trip_uuid')) {
                $table->string('trip_uuid', 64)->nullable()->index()->after('status');
            }
        });

        Schema::table('dairy_trip_routes', function (Blueprint $table) {
            $table->uuid('mcc_uuid')->nullable()->change();
        });

        Schema::table('dairy_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('dairy_trips', 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')->nullable()->after('driver_user_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dairy_trips', function (Blueprint $table) {
            if (Schema::hasColumn('dairy_trips', 'created_by_user_id')) {
                $table->dropConstrainedForeignId('created_by_user_id');
            }
        });

        Schema::table('cooperative_dispatches', function (Blueprint $table) {
            foreach (['batch_number', 'loaded_at', 'trip_uuid'] as $column) {
                if (Schema::hasColumn('cooperative_dispatches', $column)) {
                    if ($column === 'trip_uuid') {
                        $table->dropIndex(['trip_uuid']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
