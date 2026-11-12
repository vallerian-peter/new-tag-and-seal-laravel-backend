<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('configuration_revisions')->exists()) {
            return;
        }
        DB::table('configuration_publish_requests')->insert([
            'reason' => 'worker-permission-modules', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('configuration_publish_requests')->where('reason', 'worker-permission-modules')->whereNull('fulfilled_at')->delete();
    }
};
