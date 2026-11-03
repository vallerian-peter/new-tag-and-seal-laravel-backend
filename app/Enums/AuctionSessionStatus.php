<?php

namespace App\Enums;

class AuctionSessionStatus
{
    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const ENDED = 'ended';
    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [self::SCHEDULED, self::LIVE, self::ENDED, self::CANCELLED];
    }
}
