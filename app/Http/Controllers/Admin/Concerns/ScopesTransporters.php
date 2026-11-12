<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\TransporterCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A super admin (or anyone with the dairy permission) sees every transporter;
 * an MCU admin sees only the transporters registered under their MCUs.
 */
trait ScopesTransporters
{
    /** Null means every MCU; otherwise the MCUs this user administers. */
    private function scope(Request $request): ?array
    {
        $user = $request->user();
        if ($user->platform_role === 'superAdmin' || $user->hasPlatformPermission('dairy.manage')) {
            return null;
        }
        $mcus = DB::table('cooperative_user_memberships')
            ->where('user_id', $user->id)->where('status', 'approved')->where('role', 'mcu_admin')
            ->whereNotNull('mcu_uuid')->pluck('mcu_uuid')->unique()->values()->all();
        abort_if($mcus === [], 403);

        return $mcus;
    }

    private function companies(?array $mcus): Builder
    {
        return DB::table('transporter_companies as c')
            ->when($mcus !== null, fn ($q) => $q->whereIn('c.mcu_uuid', $mcus));
    }

    private function scopedUuids(Request $request): array
    {
        return $this->companies($this->scope($request))->pluck('c.uuid')->all();
    }

    private function find(Request $request, string $uuid): TransporterCompany
    {
        $company = TransporterCompany::query()->where('uuid', $uuid)->firstOrFail();
        $mcus = $this->scope($request);
        abort_unless($mcus === null || in_array($company->mcu_uuid, $mcus, true), 403);

        return $company;
    }
}
