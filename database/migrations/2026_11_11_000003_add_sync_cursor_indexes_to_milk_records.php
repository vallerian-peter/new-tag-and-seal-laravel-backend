<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'cooperative_intakes' => ['coop_int_amcos_sync_idx', 'coop_int_farmer_sync_idx'],
        'farmer_cooperative_account_ledgers' => ['fcal_amcos_sync_idx', 'fcal_farmer_sync_idx'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$amcosIndex, $farmerIndex]) {
            // Incremental pulls read by updated_at, so every row needs one.
            DB::table($table)->whereNull('updated_at')->update(['updated_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
            Schema::table($table, function (Blueprint $blueprint) use ($amcosIndex, $farmerIndex) {
                $blueprint->index(['amcos_uuid', 'updated_at', 'id'], $amcosIndex);
                $blueprint->index(['farmer_user_id', 'updated_at', 'id'], $farmerIndex);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$amcosIndex, $farmerIndex]) {
            Schema::table($table, function (Blueprint $blueprint) use ($amcosIndex, $farmerIndex) {
                $blueprint->dropIndex($amcosIndex);
                $blueprint->dropIndex($farmerIndex);
            });
        }
    }
};
