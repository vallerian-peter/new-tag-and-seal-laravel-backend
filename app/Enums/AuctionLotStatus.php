<?php

namespace App\Enums;

class AuctionLotStatus
{
    public const QUEUED = 'queued';
    public const LIVE = 'live';
    public const SOLD = 'sold';
    public const UNSOLD = 'unsold';
    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [self::QUEUED, self::LIVE, self::SOLD, self::UNSOLD, self::CANCELLED];
    }

    public static function closed(): array
    {
        return [self::SOLD, self::UNSOLD, self::CANCELLED];
    }
}
