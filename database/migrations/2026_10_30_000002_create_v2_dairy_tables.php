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
        // 1. Milk Collection Centres (MCC)
        Schema::create('milk_collection_centres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('centre_code', 50)->unique();
            $table->string('name');
            $table->foreignId('region_id')->constrained('regions');
            $table->foreignId('district_id')->constrained('districts');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Physical Refrigerated Bulk Cooling Tanks
        Schema::create('milk_tanks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('mcc_uuid')->index();
            $table->string('tank_code', 50);
            $table->decimal('capacity_liters', 10, 2)->default(3000.00);
            $table->decimal('target_temperature_celsius', 4, 1)->default(4.0);
            $table->enum('status', ['active_chilling', 'full', 'cleaning_cip', 'maintenance'])->default('active_chilling');
            $table->timestamps();

            $table->unique(['mcc_uuid', 'tank_code']);
        });

        // 3. Daily Intake Batches (Morning / Evening sessions)
        Schema::create('milk_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('batch_code', 50)->unique();
            $table->uuid('mcc_uuid')->index();
            $table->date('session_date');
            $table->enum('delivery_session', ['morning', 'evening']);
            $table->enum('status', ['open_intake', 'closed_chilled', 'dispatched'])->default('open_intake');
            $table->timestamps();

            $table->index(['mcc_uuid', 'session_date']);
        });

        // 4. Farmer Milk Intakes (Scale intake + Rapid Lab Quality Tests)
        Schema::create('milk_intakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('mcc_uuid')->index();
            $table->uuid('batch_uuid')->index();
            $table->foreignId('farmer_user_id')->constrained('users');
            $table->foreignId('clerk_user_id')->constrained('users');
            $table->decimal('gross_liters_delivered', 8, 2);
            $table->decimal('accepted_liters', 8, 2);
            $table->decimal('rejected_liters', 8, 2)->default(0.00);
            $table->decimal('temperature_celsius', 4, 1);
            $table->decimal('density_lactometer', 6, 4)->nullable();
            $table->decimal('fat_percentage', 4, 2)->nullable();
            $table->boolean('alcohol_test_passed')->default(true);
            $table->boolean('antibiotic_free')->default(true);
            $table->string('rejection_reason')->nullable();
            $table->string('scale_device_id', 100)->nullable();
            $table->dateTime('intake_timestamp')->nullable();
            $table->timestamps();

            $table->index(['mcc_uuid', 'intake_timestamp']);
            $table->index(['farmer_user_id', 'intake_timestamp']);
        });

        // 5. Double-Entry Stock Movement Ledger (Balanced inventory)
        Schema::create('milk_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('mcc_uuid')->index();
            $table->uuid('milk_tank_uuid')->index();
            $table->enum('movement_type', ['farmer_intake', 'dispatch_out', 'spillage_loss', 'cleaning_loss', 'inter_tank_transfer']);
            $table->decimal('quantity_liters', 10, 2)->comment('Signed value: + for intake, - for dispatch/loss');
            $table->uuid('reference_uuid')->index()->comment('UUID of intake, dispatch, or adjustment');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->string('notes')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['milk_tank_uuid', 'created_at']);
        });

        // 6. Dairy Payout Accruals (Quality-tiered farmer calculation)
        Schema::create('dairy_payout_accruals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('intake_uuid')->unique();
            $table->foreignId('farmer_user_id')->constrained('users');
            $table->decimal('accepted_liters', 8, 2);
            $table->decimal('base_price_per_liter', 8, 2);
            $table->decimal('quality_bonus_per_liter', 8, 2)->default(0.00);
            $table->decimal('coop_levy_per_liter', 8, 2)->default(0.00);
            $table->decimal('net_payout_amount', 12, 2);
            $table->enum('settlement_status', ['accrued', 'batched_for_payment', 'paid'])->default('accrued');
            $table->string('payment_reference', 100)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['farmer_user_id', 'settlement_status']);
        });

        // 7. Milk Dispatch Orders (Transport manifest)
        Schema::create('milk_dispatch_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('dispatch_code', 50)->unique();
            $table->uuid('mcc_uuid')->index();
            $table->string('factory_name');
            $table->foreignId('transporter_user_id')->constrained('users');
            $table->string('vehicle_registration_number', 50);
            $table->string('driver_name');
            $table->string('driver_phone', 50);
            $table->decimal('dispatched_volume_liters', 10, 2);
            $table->decimal('dispatch_temperature_celsius', 4, 1);
            $table->json('security_seal_numbers')->comment('Array of seal IDs');
            $table->enum('status', ['prepared', 'in_transit', 'delivered', 'reconciled', 'disputed'])->default('in_transit');
            $table->dateTime('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'dispatched_at']);
        });

        // 8. Milk Factory Deliveries & Reconciliation
        Schema::create('milk_factory_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('dispatch_order_uuid')->unique();
            $table->foreignId('factory_qa_officer_user_id')->constrained('users');
            $table->decimal('received_volume_liters', 10, 2);
            $table->decimal('arrival_temperature_celsius', 4, 1);
            $table->boolean('seals_intact')->default(true);
            $table->enum('factory_qa_status', ['accepted_full', 'accepted_with_deduction', 'rejected_spoiled'])->default('accepted_full');
            $table->decimal('discrepancy_liters', 8, 2)->default(0.00);
            $table->string('spillage_reason')->nullable();
            $table->string('delivery_note_number', 100)->unique();
            $table->dateTime('received_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milk_factory_deliveries');
        Schema::dropIfExists('milk_dispatch_orders');
        Schema::dropIfExists('dairy_payout_accruals');
        Schema::dropIfExists('milk_stock_movements');
        Schema::dropIfExists('milk_intakes');
        Schema::dropIfExists('milk_batches');
        Schema::dropIfExists('milk_tanks');
        Schema::dropIfExists('milk_collection_centres');
    }
};
