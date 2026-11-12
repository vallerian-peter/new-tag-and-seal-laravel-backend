<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cooperative_dispatches')) {
            Schema::create('cooperative_dispatches', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 64)->unique();
                $table->string('dispatch_code', 50)->unique();
                $table->string('centre_uuid', 64)->index();
                $table->string('commodity_code', 40)->default('milk')->index();
                $table->decimal('quantity', 12, 2);
                $table->string('unit', 20)->default('l');
                $table->string('destination_type', 40)->default('factory');
                $table->string('destination_uuid', 64)->nullable()->index();
                $table->string('destination_name', 255);
                $table->string('transporter_company_uuid', 64)->nullable()->index();
                $table->string('transporter_name', 255)->nullable();
                $table->string('vehicle_uuid', 64)->nullable()->index();
                $table->string('vehicle_plate_number', 50)->nullable();
                $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('driver_name', 255)->nullable();
                $table->string('driver_phone', 50)->nullable();
                $table->decimal('dispatch_temperature_celsius', 4, 1)->nullable();
                $table->json('security_seal_numbers')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('dispatched')->index();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['centre_uuid', 'commodity_code', 'status'], 'coop_disp_centre_comm_idx');
            });
        }

        if (Schema::hasTable('cooperative_centre_stock_ledgers')) {
            Schema::table('cooperative_centre_stock_ledgers', function (Blueprint $table) {
                if (! Schema::hasColumn('cooperative_centre_stock_ledgers', 'dispatch_uuid')) {
                    $table->string('dispatch_uuid', 64)->nullable()->index()->after('running_balance');
                }
            });
        }

        if (Schema::hasTable('transporter_companies')) {
            Schema::table('transporter_companies', function (Blueprint $table) {
                if (! Schema::hasColumn('transporter_companies', 'mcu_uuid')) {
                    $table->string('mcu_uuid', 64)->nullable()->index()->after('name');
                }
                if (! Schema::hasColumn('transporter_companies', 'amcos_uuid')) {
                    $table->string('amcos_uuid', 64)->nullable()->index()->after('mcu_uuid');
                }
            });
        }

        if (Schema::hasTable('dairy_trips')) {
            Schema::table('dairy_trips', function (Blueprint $table) {
                if (! Schema::hasColumn('dairy_trips', 'commodity_code')) {
                    $table->string('commodity_code', 40)->default('milk')->index()->after('trip_number');
                }
            });
        }

        if (Schema::hasTable('dairy_trip_routes')) {
            Schema::table('dairy_trip_routes', function (Blueprint $table) {
                if (! Schema::hasColumn('dairy_trip_routes', 'dispatch_uuid')) {
                    $table->string('dispatch_uuid', 64)->nullable()->index()->after('dispatch_order_uuid');
                }
                if (! Schema::hasColumn('dairy_trip_routes', 'centre_uuid')) {
                    $table->string('centre_uuid', 64)->nullable()->index()->after('mcc_uuid');
                }
                if (! Schema::hasColumn('dairy_trip_routes', 'commodity_code')) {
                    $table->string('commodity_code', 40)->default('milk')->index()->after('status');
                }
                if (! Schema::hasColumn('dairy_trip_routes', 'unit')) {
                    $table->string('unit', 20)->default('l')->after('liters');
                }
                if (! Schema::hasColumn('dairy_trip_routes', 'quantity')) {
                    $table->decimal('quantity', 12, 2)->nullable()->after('liters');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dairy_trip_routes')) {
            Schema::table('dairy_trip_routes', function (Blueprint $table) {
                $cols = ['dispatch_uuid', 'centre_uuid', 'commodity_code', 'unit', 'quantity'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('dairy_trip_routes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('dairy_trips')) {
            Schema::table('dairy_trips', function (Blueprint $table) {
                if (Schema::hasColumn('dairy_trips', 'commodity_code')) {
                    $table->dropColumn('commodity_code');
                }
            });
        }

        if (Schema::hasTable('transporter_companies')) {
            Schema::table('transporter_companies', function (Blueprint $table) {
                foreach (['mcu_uuid', 'amcos_uuid'] as $col) {
                    if (Schema::hasColumn('transporter_companies', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('cooperative_centre_stock_ledgers')) {
            Schema::table('cooperative_centre_stock_ledgers', function (Blueprint $table) {
                if (Schema::hasColumn('cooperative_centre_stock_ledgers', 'dispatch_uuid')) {
                    $table->dropColumn('dispatch_uuid');
                }
            });
        }

        Schema::dropIfExists('cooperative_dispatches');
    }
};

