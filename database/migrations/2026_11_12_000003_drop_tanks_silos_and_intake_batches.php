<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SRS (sections 6 and 7) tracks milk temperature at intake and during
 * transport and a stock balance per centre. Centre cooling tanks, factory
 * silos and intake batch sessions are not part of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['dairy_factory_stock_movements', 'dairy_factory_tanks', 'milk_batches', 'milk_tanks'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropColumn('milk_intakes', 'batch_uuid');
        $this->dropColumn('milk_stock_movements', 'milk_tank_uuid');
        $this->dropColumn('dairy_factories', 'current_inventory_liters');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dairy_factories', 'current_inventory_liters')) {
            Schema::table('dairy_factories', fn (Blueprint $table) => $table->decimal('current_inventory_liters', 14, 2)->default(0));
        }
        if (Schema::hasTable('milk_stock_movements') && ! Schema::hasColumn('milk_stock_movements', 'milk_tank_uuid')) {
            Schema::table('milk_stock_movements', fn (Blueprint $table) => $table->uuid('milk_tank_uuid')->nullable()->index());
        }
        if (Schema::hasTable('milk_intakes') && ! Schema::hasColumn('milk_intakes', 'batch_uuid')) {
            Schema::table('milk_intakes', fn (Blueprint $table) => $table->uuid('batch_uuid')->nullable()->index());
        }
    }

    private function dropColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
            $indexes = collect(Schema::getIndexes($table))
                ->filter(fn ($index) => in_array($column, $index['columns'], true) && ! $index['primary']);
            foreach ($indexes as $index) {
                $blueprint->dropIndex($index['name']);
            }
            $blueprint->dropColumn($column);
        });
    }
};
