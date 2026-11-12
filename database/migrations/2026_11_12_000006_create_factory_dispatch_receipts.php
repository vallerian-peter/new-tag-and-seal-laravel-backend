<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A factory operator receives (or rejects) each dispatch on a trip; a factory
 * manager then approves or rejects the trip's arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factory_operators', function (Blueprint $table) {
            if (! Schema::hasColumn('factory_operators', 'role')) {
                $table->string('role', 20)->default('operator')->after('staff_id');
            }
        });

        if (! Schema::hasTable('factory_dispatch_receipts')) {
            Schema::create('factory_dispatch_receipts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('factory_uuid', 64)->index();
                $table->string('trip_uuid', 64)->index();
                $table->string('dispatch_uuid', 64)->unique();
                $table->string('action', 20);
                $table->string('status', 30)->default('waiting_approval')->index();
                $table->decimal('dispatched_volume_liters', 12, 2)->default(0);
                $table->decimal('received_volume_liters', 12, 2)->default(0);
                $table->decimal('accepted_volume_liters', 12, 2)->default(0);
                $table->decimal('discrepancy_liters', 12, 2)->default(0);
                $table->decimal('arrival_temperature_celsius', 4, 1)->nullable();
                $table->boolean('seals_intact')->default(true);
                $table->string('qa_status', 40)->nullable();
                $table->string('reason', 255)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_notes')->nullable();
                $table->timestamps();

                $table->index(['factory_uuid', 'status'], 'fdr_fact_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_dispatch_receipts');
        if (Schema::hasColumn('factory_operators', 'role')) {
            Schema::table('factory_operators', fn (Blueprint $table) => $table->dropColumn('role'));
        }
    }
};
