<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dairy_factories')) {
            Schema::table('dairy_factories', function (Blueprint $table) {
                if (! Schema::hasColumn('dairy_factories', 'mcu_uuid')) {
                    $table->uuid('mcu_uuid')->nullable()->index()->after('factory_code');
                }
                if (! Schema::hasColumn('dairy_factories', 'amcos_uuid')) {
                    $table->uuid('amcos_uuid')->nullable()->index()->after('mcu_uuid');
                }
                if (! Schema::hasColumn('dairy_factories', 'operator_type')) {
                    $table->enum('operator_type', ['company', 'individual'])->default('company')->after('contact_email');
                }
                if (! Schema::hasColumn('dairy_factories', 'operator_name')) {
                    $table->string('operator_name', 255)->nullable()->after('operator_type');
                }
                if (! Schema::hasColumn('dairy_factories', 'operator_phone')) {
                    $table->string('operator_phone', 50)->nullable()->after('operator_name');
                }
                if (! Schema::hasColumn('dairy_factories', 'operator_registration_number')) {
                    $table->string('operator_registration_number', 100)->nullable()->after('operator_phone');
                }
                if (! Schema::hasColumn('dairy_factories', 'operator_user_id')) {
                    $table->foreignId('operator_user_id')->nullable()->after('operator_registration_number')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dairy_factories')) {
            Schema::table('dairy_factories', function (Blueprint $table) {
                $columns = [
                    'mcu_uuid',
                    'amcos_uuid',
                    'operator_type',
                    'operator_name',
                    'operator_phone',
                    'operator_registration_number',
                    'operator_user_id',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('dairy_factories', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

