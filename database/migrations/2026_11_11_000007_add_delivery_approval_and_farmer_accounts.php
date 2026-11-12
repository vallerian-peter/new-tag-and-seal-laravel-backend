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
        Schema::table('cooperative_intakes', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('payment_status');
            $table->string('source', 20)->default('staff')->after('approval_status');
            $table->decimal('declared_quantity', 12, 2)->nullable()->after('source');
            $table->timestamp('declared_at')->nullable()->after('declared_quantity');
            $table->string('farmer_note', 255)->nullable()->after('declared_at');
            $table->string('measurement_method', 20)->default('volume')->after('farmer_note');
            $table->decimal('weight_kg', 12, 2)->nullable()->after('measurement_method');
            $table->string('quality_grade', 20)->nullable()->after('weight_kg');
            $table->string('rejection_category', 40)->nullable()->after('quality_grade');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('rejection_category')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->string('review_uuid', 64)->nullable()->unique()->after('reviewed_at');
            $table->index(['amcos_uuid', 'approval_status'], 'coop_intakes_amcos_approval_idx');
        });

        Schema::create('farmer_cooperative_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->foreignId('farmer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('amcos_uuid', 64);
            $table->string('mcu_uuid', 64)->nullable();
            $table->decimal('total_credit', 14, 2)->default(0);
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('outstanding_debt', 14, 2)->default(0);
            $table->timestamp('last_entry_at')->nullable();
            $table->timestamps();
            $table->unique(['farmer_user_id', 'amcos_uuid'], 'farmer_coop_account_unique');
        });

        Schema::table('farmer_cooperative_account_ledgers', function (Blueprint $table) {
            $table->string('account_uuid', 64)->nullable()->after('uuid')->index();
        });

        $totals = DB::table('farmer_cooperative_account_ledgers')
            ->select('farmer_user_id', 'amcos_uuid')
            ->selectRaw('MAX(mcu_uuid) as mcu_uuid')
            ->selectRaw('COALESCE(SUM(credit_amount), 0) as credits')
            ->selectRaw('COALESCE(SUM(debit_amount), 0) as debits')
            ->selectRaw('MAX(entry_date) as last_entry_at')
            ->groupBy('farmer_user_id', 'amcos_uuid')
            ->get();
        foreach ($totals as $row) {
            $balance = round((float) $row->credits - (float) $row->debits, 2);
            $uuid = (string) Str::uuid();
            DB::table('farmer_cooperative_accounts')->insert([
                'uuid' => $uuid,
                'farmer_user_id' => $row->farmer_user_id,
                'amcos_uuid' => $row->amcos_uuid,
                'mcu_uuid' => $row->mcu_uuid,
                'total_credit' => round((float) $row->credits, 2),
                'total_debit' => round((float) $row->debits, 2),
                'balance' => $balance,
                'outstanding_debt' => max(0, -$balance),
                'last_entry_at' => $row->last_entry_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('farmer_cooperative_account_ledgers')
                ->where('farmer_user_id', $row->farmer_user_id)
                ->where('amcos_uuid', $row->amcos_uuid)
                ->update(['account_uuid' => $uuid]);
        }
    }

    public function down(): void
    {
        Schema::table('farmer_cooperative_account_ledgers', function (Blueprint $table) {
            $table->dropIndex(['account_uuid']);
            $table->dropColumn('account_uuid');
        });
        Schema::dropIfExists('farmer_cooperative_accounts');
        Schema::table('cooperative_intakes', function (Blueprint $table) {
            $table->dropIndex('coop_intakes_amcos_approval_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropUnique(['review_uuid']);
            $table->dropColumn([
                'approval_status', 'source', 'declared_quantity', 'declared_at', 'farmer_note',
                'measurement_method', 'weight_kg', 'quality_grade', 'rejection_category', 'reviewed_at', 'review_uuid',
            ]);
        });
    }
};
