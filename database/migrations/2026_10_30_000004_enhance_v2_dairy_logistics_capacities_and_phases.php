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
        // 1. Dairy Factories: Capacity tracking, Manager, and Status
        Schema::table('dairy_factories', function (Blueprint $table) {
            if (!Schema::hasColumn('dairy_factories', 'current_inventory_liters')) {
                $table->decimal('current_inventory_liters', 12, 2)->default(0.00)->after('total_storage_capacity_liters');
            }
            if (!Schema::hasColumn('dairy_factories', 'manager_user_id')) {
                $table->foreignId('manager_user_id')->nullable()->after('district_id')->constrained('users');
            }
            if (!Schema::hasColumn('dairy_factories', 'status')) {
                $table->string('status', 50)->default('active')->after('approval_status');
            }
        });

        // 2. Transporter Companies: Manager (Boss) and Status
        Schema::table('transporter_companies', function (Blueprint $table) {
            if (!Schema::hasColumn('transporter_companies', 'manager_user_id')) {
                $table->foreignId('manager_user_id')->nullable()->after('email')->constrained('users');
            }
            if (!Schema::hasColumn('transporter_companies', 'status')) {
                $table->string('status', 50)->default('active')->after('approval_status');
            }
        });

        // 3. Vehicles: Status
        Schema::table('transporter_vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('transporter_vehicles', 'status')) {
                $table->string('status', 50)->default('active')->after('approval_status');
            }
        });

        // 4. Drivers: Status
        Schema::table('transporter_drivers', function (Blueprint $table) {
            if (!Schema::hasColumn('transporter_drivers', 'status')) {
                $table->string('status', 50)->default('active')->after('approval_status');
            }
        });

        // 5. Factory Operators: Status
        Schema::table('factory_operators', function (Blueprint $table) {
            if (!Schema::hasColumn('factory_operators', 'status')) {
                $table->string('status', 50)->default('active')->after('is_active');
            }
        });

        // 6. Dairy Trips: Movement Phase, Storing Capacity vs Loaded Cargo Capacity
        Schema::table('dairy_trips', function (Blueprint $table) {
            if (!Schema::hasColumn('dairy_trips', 'phase')) {
                $table->string('phase', 50)->default('PREPARING')->after('status')->comment('PREPARING, IN_TRANSIT, ARRIVED, COMPLETED');
            }
            if (!Schema::hasColumn('dairy_trips', 'max_tanker_capacity_liters')) {
                $table->decimal('max_tanker_capacity_liters', 10, 2)->default(0.00)->after('total_liters');
            }
            if (!Schema::hasColumn('dairy_trips', 'current_cargo_liters')) {
                $table->decimal('current_cargo_liters', 10, 2)->default(0.00)->after('max_tanker_capacity_liters');
            }
        });

        // 7. Dairy Trip Routes: Movement Phase and Locked Destination Factory
        Schema::table('dairy_trip_routes', function (Blueprint $table) {
            if (!Schema::hasColumn('dairy_trip_routes', 'phase')) {
                $table->string('phase', 50)->default('CREATED')->after('status')->comment('CREATED, ASSIGNED, IN_TRANSIT, ARRIVED, DELIVERED');
            }
            if (!Schema::hasColumn('dairy_trip_routes', 'destination_factory_uuid')) {
                $table->uuid('destination_factory_uuid')->nullable()->after('mcc_uuid')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dairy_trip_routes', function (Blueprint $table) {
            $table->dropColumn(['phase', 'destination_factory_uuid']);
        });

        Schema::table('dairy_trips', function (Blueprint $table) {
            $table->dropColumn(['phase', 'max_tanker_capacity_liters', 'current_cargo_liters']);
        });

        Schema::table('factory_operators', function (Blueprint $table) {
            $table->dropColumn(['status']);
        });

        Schema::table('transporter_drivers', function (Blueprint $table) {
            $table->dropColumn(['status']);
        });

        Schema::table('transporter_vehicles', function (Blueprint $table) {
            $table->dropColumn(['status']);
        });

        Schema::table('transporter_companies', function (Blueprint $table) {
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn(['manager_user_id', 'status']);
        });

        Schema::table('dairy_factories', function (Blueprint $table) {
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn(['current_inventory_liters', 'manager_user_id', 'status']);
        });
    }
};

