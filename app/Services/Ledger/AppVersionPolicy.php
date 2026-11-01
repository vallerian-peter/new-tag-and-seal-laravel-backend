<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * The oldest mobile app version the platform accepts, set in the Portal.
 * Enforced at login so retired legacy screens cannot sign in again.
 */
class AppVersionPolicy
{
    public const FLAG = 'app.minimum_version';

    public const DEFAULT = '1.1.2';

    public const HEADER = 'X-App-Version';

    /** @return array{version: string, enforced: bool} */
    public static function current(): array
    {
        $flag = DB::table('platform_feature_flags')->where('key', self::FLAG)->first();
        $version = json_decode((string) $flag?->value_json, true)['version'] ?? self::DEFAULT;

        return ['version' => (string) $version, 'enforced' => (bool) ($flag?->enabled ?? false)];
    }

    /** The version the client must update to, or null when it may sign in. */
    public static function updateRequiredFor(?string $clientVersion): ?string
    {
        $policy = self::current();
        if (! $policy['enforced']) {
            return null;
        }
        $client = preg_match('/^\d+(\.\d+){0,2}/', trim((string) $clientVersion), $match) ? $match[0] : '0';

        return version_compare($client, $policy['version'], '<') ? $policy['version'] : null;
    }
}
