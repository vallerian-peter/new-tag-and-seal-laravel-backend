<?php

namespace App\Services\Hiring;

use Illuminate\Support\Facades\DB;

class GrantSources
{
    public function write(string $farm, int $user, string $type, string $source, array $permissions, ?int $actor): void
    {
        DB::transaction(function () use ($farm, $user, $type, $source, $permissions, $actor) {
            DB::table('farms')->where('uuid', $farm)->lockForUpdate()->first();
            $existing = DB::table('farm_access_grants')->where('farm_uuid', $farm)->where('user_id', $user)->lockForUpdate()->first();
            if ($existing && ! DB::table('farm_access_grant_sources')->where('farm_uuid', $farm)->where('user_id', $user)->lockForUpdate()->first(['id'])) {
                DB::table('farm_access_grant_sources')->insert(['farm_uuid' => $farm, 'user_id' => $user, 'source_type' => 'manual', 'source_id' => 'legacy', 'permissions' => $existing->permissions, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('farm_access_grant_sources')->updateOrInsert(['farm_uuid' => $farm, 'user_id' => $user, 'source_type' => $type, 'source_id' => $source], ['permissions' => json_encode(array_values($permissions)), 'created_at' => now(), 'updated_at' => now()]);
            $effective = [];
            foreach (DB::table('farm_access_grant_sources')->where('farm_uuid', $farm)->where('user_id', $user)->lockForUpdate()->pluck('permissions') as $raw) {
                $effective = array_merge($effective, json_decode($raw, true) ?: []);
            }
            DB::table('farm_access_grants')->updateOrInsert(['farm_uuid' => $farm, 'user_id' => $user], ['permissions' => json_encode(array_values(array_unique($effective))), 'granted_by_user_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
