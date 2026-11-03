<?php

namespace App\Enums;

/**
 * Lifecycle of an on-demand veterinary service request.
 *
 * pending -> accepted -> travelling -> arrived -> in_progress -> completed
 *         \-> cancelled (farmer, or vet after accepting) / expired (no vet found)
 */
class VetServiceRequestStatus
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const TRAVELLING = 'travelling';
    public const ARRIVED = 'arrived';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public static function all(): array
    {
        return [
            self::PENDING, self::ACCEPTED, self::TRAVELLING, self::ARRIVED,
            self::IN_PROGRESS, self::COMPLETED, self::CANCELLED, self::EXPIRED,
        ];
    }

    /** Statuses in which a vet is actively engaged on the job. */
    public static function active(): array
    {
        return [self::ACCEPTED, self::TRAVELLING, self::ARRIVED, self::IN_PROGRESS];
    }

    /** Statuses that still allow the farmer to cancel. */
    public static function cancellable(): array
    {
        return [self::PENDING, self::ACCEPTED, self::TRAVELLING];
    }

    public static function terminal(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::EXPIRED];
    }

    /**
     * The status transitions a vet may perform, in order. Each key may only move
     * to the value that follows it.
     */
    public static function vetProgression(): array
    {
        return [
            self::ACCEPTED => self::TRAVELLING,
            self::TRAVELLING => self::ARRIVED,
            self::ARRIVED => self::IN_PROGRESS,
            self::IN_PROGRESS => self::COMPLETED,
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return (self::vetProgression()[$from] ?? null) === $to;
    }
}
