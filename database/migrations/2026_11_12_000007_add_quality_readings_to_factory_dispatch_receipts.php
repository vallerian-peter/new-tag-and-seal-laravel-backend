<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('factory_dispatch_receipts', 'quality_readings')) {
            Schema::table('factory_dispatch_receipts', function (Blueprint $table) {
                $table->json('quality_readings')->nullable()->after('qa_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('factory_dispatch_receipts', 'quality_readings')) {
            Schema::table('factory_dispatch_receipts', function (Blueprint $table) {
                $table->dropColumn('quality_readings');
            });
        }
    }
};
