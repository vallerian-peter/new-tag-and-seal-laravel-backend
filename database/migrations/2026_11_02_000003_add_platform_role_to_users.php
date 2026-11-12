<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('platform_role', 20)->default('appUser');
        });

        DB::table('users')->where('role', 'systemUser')->update(['platform_role' => 'admin']);
        $managers = DB::table('user_platform_permissions')
            ->join('platform_permissions', 'platform_permissions.id', '=', 'user_platform_permissions.permission_id')
            ->where('platform_permissions.code', 'permissions.manage')
            ->pluck('user_platform_permissions.user_uuid');
        DB::table('users')->where('role', 'systemUser')->whereIn('uuid', $managers)->update(['platform_role' => 'superAdmin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('platform_role');
        });
    }
};
