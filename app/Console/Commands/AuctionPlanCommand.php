<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AuctionCentre;
use App\Models\AuctionOfficer;
use App\Models\Livestock;
use App\Models\User;
use App\Services\Auction\AuctionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Plan an auction session from the shell, the same way the management portal
 * will through the admin API. Handy until the portal screen exists.
 *
 *   php artisan auction:plan --centre=PUGU --officer=juma@example.com \
 *       --title="Pugu market day" --at="2026-11-05 09:00" \
 *       --lot=55262622:500000:10000 --lot=43562789:300000:5000:400000:90
 *
 * Each --lot is TAG:startingPrice:minIncrement[:reservePrice[:durationSeconds]].
 */
class AuctionPlanCommand extends Command
{
    protected $signature = 'auction:plan
        {--centre= : Centre code or uuid}
        {--officer= : Officer email, username or officer number to assign}
        {--title= : Session title}
        {--at= : Scheduled date/time, e.g. "2026-11-05 09:00" (default: now)}
        {--payment= : Payment instructions shown to winners}
        {--lot=* : TAG:startingPrice:minIncrement[:reservePrice[:durationSeconds]]}';

    protected $description = 'Plan an auction session at a centre and assign it to an officer';

    public function handle(AuctionService $service): int
    {
        $centreKey = (string) $this->option('centre');
        $centre = AuctionCentre::where('uuid', $centreKey)->orWhere('code', strtoupper($centreKey))->first();
        if (! $centre) {
            $this->error('Centre not found. Pass --centre=CODE (see auction:centre --list).');

            return self::INVALID;
        }

        $handle = (string) $this->option('officer');
        $officer = AuctionOfficer::where('officerNo', strtoupper($handle))->orWhere('uuid', $handle)->first();
        if (! $officer) {
            $user = User::where('email', $handle)->orWhere('username', $handle)->first();
            $officer = $user ? AuctionOfficer::where('userId', $user->id)->first() : null;
        }
        if (! $officer || ! $officer->userId) {
            $this->error('Officer not found. Pass --officer=email (see auction:officer --list).');

            return self::INVALID;
        }
        if (! $officer->isVerified()) {
            $this->error("{$officer->fullName} is not verified yet. Approve with: auction:officer {$handle} --approve");

            return self::INVALID;
        }

        $lots = [];
        foreach ((array) $this->option('lot') as $spec) {
            $parts = explode(':', (string) $spec);
            if (count($parts) < 3) {
                $this->error("Bad --lot \"{$spec}\". Use TAG:startingPrice:minIncrement[:reservePrice[:durationSeconds]].");

                return self::INVALID;
            }
            $animal = Livestock::where('identificationNumber', $parts[0])->orWhere('rfidTagId', $parts[0])->first();
            if (! $animal) {
                $this->error("No animal with tag {$parts[0]}.");

                return self::INVALID;
            }
            $lots[] = array_filter([
                'livestockUuid' => $animal->uuid,
                'startingPrice' => (float) $parts[1],
                'minIncrement' => (float) $parts[2],
                'reservePrice' => isset($parts[3]) && $parts[3] !== '' ? (float) $parts[3] : null,
                'durationSeconds' => isset($parts[4]) ? (int) $parts[4] : null,
            ], fn ($v) => $v !== null);
        }

        $admin = User::where('role', UserRole::SYSTEM_USER)->first();
        if (! $admin) {
            $this->error('No systemUser account exists to plan on behalf of.');

            return self::FAILURE;
        }

        try {
            $session = $service->createSession($admin, [
                'centreUuid' => $centre->uuid,
                'hostUserId' => $officer->userId,
                'title' => $this->option('title') ?: "{$centre->name} auction",
                'scheduledAt' => $this->option('at') ? Carbon::parse($this->option('at')) : now(),
                'paymentInstructions' => $this->option('payment'),
                'lots' => $lots,
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Planned \"{$session->title}\" at {$centre->name} for {$officer->fullName}");
        $this->line("  uuid: {$session->uuid}");
        $this->line('  when: '.$session->scheduledAt?->format('Y-m-d H:i'));
        $this->line('  lots: '.count($lots));
        $this->line('The officer will see it under Assigned to me and can begin it from the app.');

        return self::SUCCESS;
    }
}
