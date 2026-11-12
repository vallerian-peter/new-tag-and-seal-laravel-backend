<?php

namespace App\Services\Dairy;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side authorisation for cooperative (MCU -> AMCOS -> CC / Farmer) data.
 * Staff access is granted only through an APPROVED cooperative_user_memberships row.
 */
class CooperativeAccess
{
    public const ADMIN_ROLES = ['mcu_admin', 'amcos_admin'];

    public static function deny(string $message = 'You are not authorised for this cooperative.'): never
    {
        throw new HttpResponseException(response()->json(['status' => false, 'message' => $message], 403));
    }

    public static function staffMembership(int $userId, string $amcosUuid, bool $admin = false): ?object
    {
        return DB::table('cooperative_user_memberships')
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->when($admin, fn ($query) => $query->whereIn('role', self::ADMIN_ROLES))
            ->where(function ($q) use ($amcosUuid) {
                $q->where('amcos_uuid', $amcosUuid)
                    ->orWhere(function ($q2) use ($amcosUuid) {
                        // MCU admins may act within AMCOS that belong to their MCU.
                        $mcu = DB::table('cooperative_amcos')->where('uuid', $amcosUuid)->value('mcu_uuid');
                        $q2->where('role', 'mcu_admin')->where('mcu_uuid', $mcu)->whereNotNull('mcu_uuid');
                    });
            })
            ->first();
    }

    /** Require approved staff; optionally an admin and/or access to a specific centre. */
    public static function requireStaff(int $userId, string $amcosUuid, bool $admin = false, ?string $centreUuid = null): object
    {
        $m = self::staffMembership($userId, $amcosUuid, $admin);
        if (! $m) {
            self::deny('Your membership of this cooperative is not approved.');
        }
        if ($centreUuid && ! self::canUseCentre($m, $centreUuid)) {
            self::deny('You are not assigned to this collection centre.');
        }

        return $m;
    }

    public static function canUseCentre(object $membership, string $centreUuid): bool
    {
        // Any approved staff member of an AMCOS has access to all collection centres under their AMCOS
        $centre = DB::table('cooperative_collection_centres')
            ->where('uuid', $centreUuid)
            ->first(['amcos_uuid', 'mcu_uuid']);

        if ($centre && $centre->amcos_uuid === $membership->amcos_uuid) {
            return true;
        }

        if (in_array($membership->role, self::ADMIN_ROLES, true)) {
            return true;
        }
        $assigned = json_decode((string) ($membership->assigned_centre_uuids ?? '[]'), true) ?: [];

        return in_array($centreUuid, $assigned, true);
    }

    /** AMCOS uuids the user may read as approved staff. */
    public static function staffAmcosUuids(int $userId): array
    {
        return DB::table('cooperative_user_memberships')
            ->where('user_id', $userId)->where('status', 'approved')
            ->pluck('amcos_uuid')->all();
    }
}
