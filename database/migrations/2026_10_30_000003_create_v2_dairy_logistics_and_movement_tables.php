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
        // 1. Milk Transporter Companies / Fleet Organizations
        Schema::create('transporter_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('license_number', 100)->unique();
            $table->string('tin_number', 50)->nullable();
            $table->string('contact_person');
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->json('document_urls')->nullable()->comment('Business license, carrier permit, LATRA/insurance docs');
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->text('approval_remarks')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['approval_status', 'is_active'], 'tc_appr_act_idx');
        });

        // 2. Milk Tanker Transport Vehicles
        Schema::create('transporter_vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('transporter_company_uuid')->index();
            $table->string('registration_number', 50)->unique();
            $table->decimal('tanker_capacity_liters', 10, 2);
            $table->string('make_model')->nullable();
            $table->string('calibration_certificate_url')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['transporter_company_uuid', 'approval_status'], 'veh_co_appr_idx');
        });

        // 3. Milk Tanker Drivers
        Schema::create('transporter_drivers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('transporter_company_uuid')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->string('driver_license_number', 50);
            $table->uuid('assigned_vehicle_uuid')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->timestamps();

            $table->index(['transporter_company_uuid', 'is_active'], 'driver_co_act_idx');
        });

        // 4. Dairy Processing Factories & Bulk Warehouses
        Schema::create('dairy_factories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('factory_code', 50)->unique();
            $table->string('registration_number', 100);
            $table->string('dairy_board_license_no', 100)->nullable();
            $table->string('contact_name');
            $table->string('contact_phone', 50);
            $table->string('contact_email')->nullable();
            $table->foreignId('region_id')->constrained('regions');
            $table->foreignId('district_id')->constrained('districts');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('total_storage_capacity_liters', 12, 2)->default(50000.00);
            $table->json('document_urls')->nullable()->comment('Registration certificates, Dairy Board License, TBS certs');
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->text('approval_remarks')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['approval_status', 'is_active'], 'df_appr_act_idx');
        });

        // 5. Dairy Factory Intake Operators (Plant staff receiving milk tankers)
        Schema::create('factory_operators', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('factory_uuid')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->string('staff_id', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['factory_uuid', 'is_active'], 'fo_fact_act_idx');
        });

        // 6. Dairy Factory Bulk Silos / Storage Tanks
        Schema::create('dairy_factory_tanks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('factory_uuid')->index();
            $table->string('tank_code', 50);
            $table->decimal('capacity_liters', 10, 2);
            $table->decimal('current_stock_liters', 10, 2)->default(0.00);
            $table->decimal('target_temperature_celsius', 4, 1)->default(4.0);
            $table->enum('status', ['active_chilling', 'full', 'cleaning_cip', 'maintenance'])->default('active_chilling');
            $table->timestamps();

            $table->unique(['factory_uuid', 'tank_code'], 'df_tanks_code_unq');
        });

        // 7. Dairy Factory Double-Entry Stock Movement Ledger
        Schema::create('dairy_factory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('factory_uuid')->index();
            $table->uuid('factory_tank_uuid')->index();
            $table->enum('movement_type', ['tanker_intake', 'processing_out', 'spillage_loss', 'cleaning_loss', 'inter_tank_transfer']);
            $table->decimal('quantity_liters', 10, 2)->comment('+ for received tanker intake, - for processing out or loss');
            $table->uuid('reference_uuid')->nullable()->index()->comment('UUID of delivery note or batch');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->string('notes')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['factory_tank_uuid', 'created_at'], 'df_stk_tank_idx');
        });

        // 8. Dairy Milk Trips (Multi-stop milk tanker journey)
        Schema::create('dairy_trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('trip_number', 50)->unique();
            $table->uuid('transporter_company_uuid')->index();
            $table->foreignId('driver_user_id')->constrained('users');
            $table->uuid('vehicle_uuid')->index();
            $table->string('vehicle_plate_number', 50);
            $table->uuid('destination_factory_uuid')->index();
            $table->enum('status', ['created', 'pending_approval', 'approved', 'in_transit', 'arrived', 'completed', 'cancelled'])->default('created');
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->text('approval_remarks')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('total_liters', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'driver_user_id'], 'dt_stat_drv_idx');
        });

        // 9. Dairy Trip Routes (Individual collection legs + supports mid-trip AddRoutes)
        Schema::create('dairy_trip_routes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('dairy_trip_uuid')->index();
            $table->uuid('dispatch_order_uuid')->nullable()->index();
            $table->uuid('mcc_uuid')->index();
            $table->integer('sequence_order')->default(1);
            $table->enum('stop_type', ['pickup', 'dropoff'])->default('pickup');
            $table->enum('status', ['assigned', 'loaded', 'in_transit', 'arrived', 'delivered'])->default('assigned');
            $table->decimal('liters', 10, 2);
            $table->decimal('temperature_celsius', 4, 1)->default(4.0);
            $table->json('security_seal_numbers')->nullable();
            $table->boolean('added_mid_trip')->default(false)->comment('True if added on-the-road after trip start');
            $table->dateTime('added_at')->nullable();
            $table->timestamps();

            $table->index(['dairy_trip_uuid', 'sequence_order'], 'dtr_trip_seq_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dairy_trip_routes');
        Schema::dropIfExists('dairy_trips');
        Schema::dropIfExists('dairy_factory_stock_movements');
        Schema::dropIfExists('dairy_factory_tanks');
        Schema::dropIfExists('factory_operators');
        Schema::dropIfExists('dairy_factories');
        Schema::dropIfExists('transporter_drivers');
        Schema::dropIfExists('transporter_vehicles');
        Schema::dropIfExists('transporter_companies');
    }
};
