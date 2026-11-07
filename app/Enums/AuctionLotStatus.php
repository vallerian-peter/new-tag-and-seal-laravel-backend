<?php

namespace App\Enums;

class AuctionLotStatus
{
    public const QUEUED = 'queued';
    public const LIVE = 'live';
    /** Bidding ended with bids; the auctioneer decides sold or not sold. */
    public const AWAITING_DECISION = 'awaiting_decision';
    public const SOLD = 'sold';
    public const UNSOLD = 'unsold';
    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [self::QUEUED, self::LIVE, self::AWAITING_DECISION, self::SOLD, self::UNSOLD, self::CANCELLED];
    }

    public static function closed(): array
    {
        return [self::SOLD, self::UNSOLD, self::CANCELLED];
    }
}
