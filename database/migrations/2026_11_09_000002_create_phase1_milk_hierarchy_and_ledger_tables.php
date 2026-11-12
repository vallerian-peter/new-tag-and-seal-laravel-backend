<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Measurement Units Table (Liters, Milliliters, Kilograms, Grams, Tonnes, Pieces, Crates)
        if (! Schema::hasTable('measurement_units')) {
            Schema::create('measurement_units', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code', 20)->unique()->comment('l, ml, kg, g, ton, pcs, crate');
                $table->string('name', 100);
                $table->string('symbol', 20);
                $table->enum('unit_type', ['volume', 'weight', 'count'])->default('volume');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            $now = now();
            DB::table('measurement_units')->insert([
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'l',
                    'name' => 'Liters',
                    'symbol' => 'L',
                    'unit_type' => 'volume',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'ml',
                    'name' => 'Milliliters',
                    'symbol' => 'mL',
                    'unit_type' => 'volume',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'kg',
                    'name' => 'Kilograms',
                    'symbol' => 'kg',
                    'unit_type' => 'weight',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'g',
                    'name' => 'Grams',
                    'symbol' => 'g',
                    'unit_type' => 'weight',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'ton',
                    'name' => 'Metric Tonnes',
                    'symbol' => 't',
                    'unit_type' => 'weight',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'pcs',
                    'name' => 'Pieces / Heads',
                    'symbol' => 'pcs',
                    'unit_type' => 'count',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        // 2. MCU / Cooperative Unions (Top of Hierarchy: MCU -> AMCOS -> Farmer / CC)
        if (! Schema::hasTable('cooperative_mcus')) {
            Schema::create('cooperative_mcus', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('mcu_code', 50)->unique();
                $table->string('name');
                $table->string('registration_number', 100)->nullable();
                $table->unsignedBigInteger('region_id')->nullable();
                $table->unsignedBigInteger('district_id')->nullable();
                $table->string('contact_name')->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 3. AMCOS / Cooperatives (Under MCU or Independent; handles everything on its own)
        if (! Schema::hasTable('cooperative_amcos')) {
            Schema::create('cooperative_amcos', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('amcos_code', 50)->unique();
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->string('name');
                $table->string('default_unit', 20)->default('l')->comment('References measurement_units.code: l, kg, pcs');
                $table->string('registration_number', 100)->nullable();
                $table->unsignedBigInteger('region_id')->nullable();
                $table->unsignedBigInteger('district_id')->nullable();
                $table->unsignedBigInteger('ward_id')->nullable();
                $table->unsignedBigInteger('village_id')->nullable();
                $table->string('contact_name')->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 4. Cooperative Staff / AMCOS User Memberships
        if (! Schema::hasTable('cooperative_user_memberships')) {
            Schema::create('cooperative_user_memberships', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->index();
                $table->string('role', 40)->default('centre_clerk')->comment('mcu_admin, amcos_admin, centre_clerk');
                $table->json('assigned_centre_uuids')->nullable();
                $table->enum('status', ['pending', 'approved', 'rejected', 'inactive'])->default('approved');
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'amcos_uuid'], 'coop_usr_mem_unq');
            });
        }

        // 5. Generalized Cooperative Collection Centres (CC under MCU -> AMCOS)
        if (! Schema::hasTable('cooperative_collection_centres')) {
            Schema::create('cooperative_collection_centres', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->nullable()->index();
                $table->string('default_unit', 20)->default('l')->comment('References measurement_units.code: l, kg, pcs');
                $table->string('centre_code', 50)->unique();
                $table->string('name');
                $table->unsignedBigInteger('region_id')->nullable();
                $table->unsignedBigInteger('district_id')->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->string('contact_name')->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->decimal('opening_balance', 12, 2)->default(0.00);
                $table->decimal('current_balance', 12, 2)->default(0.00);
                $table->decimal('total_collected', 12, 2)->default(0.00);
                $table->decimal('total_dispatched', 12, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 6. Cooperative Seasons & Pricing (Unit-agnostic: price_per_unit with unit = 'l', 'kg', 'pcs')
        if (! Schema::hasTable('cooperative_seasons')) {
            Schema::create('cooperative_seasons', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('season_code', 50)->unique();
                $table->string('unit', 20)->default('l')->comment('References measurement_units.code: l, kg, pcs');
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->nullable()->index();
                $table->string('name');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->decimal('price_per_unit', 12, 2)->comment('Seasonal price per unit');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        // 7. Cooperative Farmer Memberships + OTP Verification
        // STRICT RULE: A farmer can ONLY be registered to ONE AMCOS!
        if (! Schema::hasTable('cooperative_farmer_memberships')) {
            Schema::create('cooperative_farmer_memberships', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->index();
                $table->foreignId('farmer_user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('farmer_id')->nullable()->index();
                $table->string('member_number', 60)->unique();
                $table->string('farmer_name');
                $table->string('farmer_phone', 32)->index();
                $table->json('enrolled_centre_uuids')->nullable()->comment('Collection Centre UUIDs where farmer is enrolled');
                $table->enum('status', ['pending_otp', 'active', 'inactive', 'archived'])->default('pending_otp');
                $table->string('otp_code', 10)->nullable();
                $table->dateTime('otp_expires_at')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->unsignedBigInteger('enrolled_by_user_id')->nullable();
                $table->timestamps();

                // Enforce: One Farmer -> Only ONE AMCOS!
                $table->unique('farmer_user_id', 'coop_farmer_single_amcos_unq');
            });
        }

        // 8. Generalized Cooperative Intakes / Deliveries (Unit-agnostic: gross_quantity, accepted_quantity, rejected_quantity, unit)
        if (! Schema::hasTable('cooperative_intakes')) {
            Schema::create('cooperative_intakes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('receipt_number', 60)->unique();
                $table->string('unit', 20)->default('l')->comment('References measurement_units.code: l, kg, pcs');
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->index();
                $table->uuid('centre_uuid')->index();
                $table->uuid('season_uuid')->nullable()->index();
                $table->string('delivery_session', 20)->default('morning')->comment('morning, evening, full_day');
                $table->foreignId('farmer_user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('clerk_user_id')->nullable();
                $table->decimal('gross_quantity', 12, 2);
                $table->decimal('accepted_quantity', 12, 2);
                $table->decimal('rejected_quantity', 12, 2)->default(0.00);
                $table->string('rejection_reason')->nullable();
                $table->boolean('is_tested')->default(false);
                $table->string('test_status', 30)->default('not_tested')->comment('not_tested, tested, device_error');
                $table->decimal('temperature_celsius', 5, 2)->nullable();
                $table->decimal('density_lactometer', 6, 4)->nullable();
                $table->decimal('fat_percentage', 5, 2)->nullable();
                $table->boolean('alcohol_test_passed')->nullable();
                $table->boolean('antibiotic_free')->nullable();
                $table->json('quality_metrics_json')->nullable()->comment('General quality metrics JSON');
                $table->string('scale_device_id', 100)->nullable();
                $table->decimal('price_per_unit', 12, 2)->default(0.00);
                $table->decimal('payable_amount', 12, 2)->default(0.00);
                $table->decimal('paid_amount', 12, 2)->default(0.00);
                $table->string('payment_status', 30)->default('unpaid')->comment('unpaid, partially_paid, paid');
                $table->dateTime('intake_timestamp');
                $table->timestamps();

                $table->index(['centre_uuid', 'intake_timestamp'], 'coop_int_cc_dt_idx');
                $table->index(['farmer_user_id', 'intake_timestamp'], 'coop_int_frm_dt_idx');
            });
        }

        // 9. Universal Farmer Cooperative Account Ledger (Credits, Debits, Payouts, Expenses/Debts)
        if (! Schema::hasTable('farmer_cooperative_account_ledgers')) {
            Schema::create('farmer_cooperative_account_ledgers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('unit', 20)->nullable()->comment('l, kg, pcs');
                $table->decimal('quantity', 12, 2)->nullable();
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->index();
                $table->uuid('centre_uuid')->nullable()->index();
                $table->foreignId('farmer_user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('farmer_id')->nullable()->index();
                $table->uuid('intake_uuid')->nullable()->index();
                $table->uuid('season_uuid')->nullable()->index();
                $table->string('entry_type', 40)->comment('credit_delivery, debit_payout, debit_expense, credit_adjustment, debit_adjustment');
                $table->decimal('credit_amount', 12, 2)->default(0.00);
                $table->decimal('debit_amount', 12, 2)->default(0.00);
                $table->decimal('running_balance', 12, 2)->default(0.00)->comment('Net Credit - Debit owed to farmer');
                $table->string('payment_method', 40)->nullable();
                $table->string('reference_number', 100)->nullable();
                $table->string('description')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->dateTime('entry_date');
                $table->timestamps();

                $table->index(['farmer_user_id', 'amcos_uuid', 'entry_date'], 'fcal_farmer_amcos_dt_idx');
            });
        }

        // 10. Unit-Agnostic Cooperative Centre Stock Ledger (Phase 1 Balance)
        if (! Schema::hasTable('cooperative_centre_stock_ledgers')) {
            Schema::create('cooperative_centre_stock_ledgers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('unit', 20)->default('l')->comment('l, kg, pcs');
                $table->uuid('mcu_uuid')->nullable()->index();
                $table->uuid('amcos_uuid')->nullable()->index();
                $table->uuid('centre_uuid')->index();
                $table->string('movement_type', 40)->comment('opening_balance, farmer_intake, outgoing_dispatch, spillage_loss, correction');
                $table->decimal('quantity', 12, 2)->comment('+ for intake/opening, - for outgoing/loss');
                $table->decimal('running_balance', 12, 2);
                $table->string('destination_or_reason')->nullable();
                $table->uuid('reference_uuid')->nullable()->index();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->dateTime('movement_date');
                $table->timestamps();
            });
        }

        // Seed default MCU -> AMCOS -> CC -> Season hierarchy if empty
        if (DB::table('cooperative_mcus')->count() === 0) {
            $regionId = DB::table('regions')->value('id') ?? 1;
            $districtId = DB::table('districts')->where('regionId', $regionId)->value('id')
                ?? DB::table('districts')->value('id') ?? 1;

            $mcuUuid = (string) Str::uuid();
            DB::table('cooperative_mcus')->insert([
                'uuid' => $mcuUuid,
                'mcu_code' => 'MCU-TZ-001',
                'name' => 'Tanzania Cooperative Union (TCU)',
                'registration_number' => 'MCU/REG/2026/001',
                'region_id' => $regionId,
                'district_id' => $districtId,
                'contact_name' => 'Mwenyekiti wa MCU',
                'contact_phone' => '255754000111',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $amcosUuid = (string) Str::uuid();
            DB::table('cooperative_amcos')->insert([
                'uuid' => $amcosUuid,
                'amcos_code' => 'AMCOS-001',
                'mcu_uuid' => $mcuUuid,
                'name' => 'Umoja Cooperative AMCOS Ltd',
                'default_unit' => 'l',
                'registration_number' => 'AMCOS/REG/2026/101',
                'region_id' => $regionId,
                'district_id' => $districtId,
                'contact_name' => 'Katibu wa AMCOS',
                'contact_phone' => '255754000222',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $legacyCentres = Schema::hasTable('milk_collection_centres')
                ? DB::table('milk_collection_centres')->get()
                : collect();

            if ($legacyCentres->isNotEmpty()) {
                foreach ($legacyCentres as $lc) {
                    DB::table('cooperative_collection_centres')->insertOrIgnore([
                        'uuid' => $lc->uuid,
                        'mcu_uuid' => $mcuUuid,
                        'amcos_uuid' => $amcosUuid,
                        'default_unit' => 'l',
                        'centre_code' => $lc->centre_code,
                        'name' => $lc->name,
                        'region_id' => $lc->region_id,
                        'district_id' => $lc->district_id,
                        'latitude' => $lc->latitude,
                        'longitude' => $lc->longitude,
                        'contact_name' => 'Karani Mkuu',
                        'contact_phone' => '255754000333',
                        'opening_balance' => 0,
                        'current_balance' => 0,
                        'total_collected' => 0,
                        'total_dispatched' => 0,
                        'is_active' => $lc->is_active ?? true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                DB::table('cooperative_collection_centres')->insert([
                    'uuid' => (string) Str::uuid(),
                    'mcu_uuid' => $mcuUuid,
                    'amcos_uuid' => $amcosUuid,
                    'default_unit' => 'l',
                    'centre_code' => 'CC-UMOJA-01',
                    'name' => 'Kituo cha Umoja (CC 1)',
                    'region_id' => $regionId,
                    'district_id' => $districtId,
                    'contact_name' => 'Karani Mkuu',
                    'contact_phone' => '255754000333',
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'total_collected' => 0,
                    'total_dispatched' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('cooperative_seasons')->insert([
                'uuid' => (string) Str::uuid(),
                'season_code' => 'MSIMU-2026-A',
                'unit' => 'l',
                'mcu_uuid' => $mcuUuid,
                'amcos_uuid' => $amcosUuid,
                'name' => 'Msimu wa Uzalishaji 2026 (Masika/Kiangazi)',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'price_per_unit' => 1200.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_centre_stock_ledgers');
        Schema::dropIfExists('farmer_cooperative_account_ledgers');
        Schema::dropIfExists('cooperative_intakes');
        Schema::dropIfExists('cooperative_farmer_memberships');
        Schema::dropIfExists('cooperative_seasons');
        Schema::dropIfExists('cooperative_collection_centres');
        Schema::dropIfExists('cooperative_user_memberships');
        Schema::dropIfExists('cooperative_amcos');
        Schema::dropIfExists('cooperative_mcus');
        Schema::dropIfExists('measurement_units');
    }
};
