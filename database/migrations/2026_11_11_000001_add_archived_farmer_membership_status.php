<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        if (! Schema::hasTable('cooperative_farmer_memberships')) {
            return;
        }

        DB::statement("ALTER TABLE cooperative_farmer_memberships MODIFY COLUMN status ENUM('pending_otp', 'active', 'inactive', 'archived') NOT NULL DEFAULT 'pending_otp'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        if (! Schema::hasTable('cooperative_farmer_memberships')) {
            return;
        }

        DB::statement("UPDATE cooperative_farmer_memberships SET status = 'inactive' WHERE status = 'archived'");
        DB::statement("ALTER TABLE cooperative_farmer_memberships MODIFY COLUMN status ENUM('pending_otp', 'active', 'inactive') NOT NULL DEFAULT 'pending_otp'");
    }
};
