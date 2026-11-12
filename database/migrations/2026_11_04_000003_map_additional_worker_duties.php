<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $modules = ['teethclipping' => 'teeth-clipping', 'taildocking' => 'tail-docking', 'ironinjection' => 'iron-injection', 'livestockmarking' => 'livestock-marking', 'stagechange' => 'stage-change', 'prepucecondition' => 'prepuce-condition'];
        foreach (DB::table('event_definitions')->get(['uuid', 'code']) as $definition) {
            $code = preg_replace('/[^a-z]/', '', strtolower($definition->code));
            foreach ($modules as $suffix => $module) {
                if (str_contains($code, $suffix)) {
                    DB::table('event_definitions')->where('uuid', $definition->uuid)->update(['access_module_code' => $module]);
                }
            }
        }
        if (DB::table('configuration_revisions')->exists()) {
            DB::table('configuration_publish_requests')->insert(['reason' => 'additional-worker-duties', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Module assignments are safe additive configuration and may have been edited.
        DB::table('configuration_publish_requests')->where('reason', 'additional-worker-duties')->whereNull('fulfilled_at')->delete();
    }
};
