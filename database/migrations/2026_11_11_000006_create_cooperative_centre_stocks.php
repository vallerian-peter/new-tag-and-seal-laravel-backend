<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A centre keeps one stock balance per goods type, each in its own unit.
 * Existing centre balances were all milk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cooperative_centre_stocks')) {
            Schema::create('cooperative_centre_stocks', function (Blueprint $table) {
                $table->id();
                $table->string('centre_uuid', 64);
                $table->string('commodity_code', 40);
                $table->string('unit', 20);
                $table->decimal('current_balance', 12, 2)->default(0);
                $table->decimal('total_collected', 12, 2)->default(0);
                $table->decimal('total_dispatched', 12, 2)->default(0);
                $table->timestamps();
                $table->unique(['centre_uuid', 'commodity_code']);
            });
        }

        Schema::table('cooperative_centre_stock_ledgers', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperative_centre_stock_ledgers', 'commodity_code')) {
                $table->string('commodity_code', 40)->default('milk')->after('unit')->index();
            }
        });

        $now = now();
        DB::table('cooperative_collection_centres')->orderBy('id')->each(function ($centre) use ($now) {
            DB::table('cooperative_centre_stocks')->insertOrIgnore([
                'centre_uuid' => $centre->uuid,
                'commodity_code' => 'milk',
                'unit' => $centre->default_unit ?: 'l',
                'current_balance' => $centre->current_balance ?? 0,
                'total_collected' => $centre->total_collected ?? 0,
                'total_dispatched' => $centre->total_dispatched ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('cooperative_centre_stock_ledgers', 'commodity_code')) {
            Schema::table('cooperative_centre_stock_ledgers', function (Blueprint $table) {
                $table->dropIndex(['commodity_code']);
                $table->dropColumn('commodity_code');
            });
        }
        Schema::dropIfExists('cooperative_centre_stocks');
    }
};
