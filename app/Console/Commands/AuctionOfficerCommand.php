<?php

namespace App\Console\Commands;

use App\Models\AuctionCentre;
use App\Models\AuctionOfficer;
use App\Services\Auction\AuctionOfficerService;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Approve, reject or list auction officers from the shell.
 *
 *   php artisan auction:officer --list
 *   php artisan auction:officer dalali@example.com --approve --centre=PUGU
 *   php artisan auction:officer dalali@example.com --reject --notes="Licence expired"
 */
class AuctionOfficerCommand extends Command
{
    protected $signature = 'auction:officer
        {handle? : Officer email, username, officer number or uuid}
        {--approve : Verify the officer and activate the centre membership}
        {--reject : Reject the application}
        {--centre= : Centre code or uuid to attach on approval (defaults to the requested centre)}
        {--role=auctioneer : manager, auctioneer or clerk}
        {--notes= : Verification notes}
        {--list : List officers and their state}';

    protected $description = 'Verify or list auction officers';

    public function handle(AuctionOfficerService $service): int
    {
        if ($this->option('list') || ! $this->argument('handle')) {
            foreach (AuctionOfficer::with('requestedCentre')->orderBy('verificationStatus')->get() as $o) {
                $this->line(sprintf('%-10s %-24s %-10s %-9s centre=%s', $o->officerNo, $o->fullName, $o->verificationStatus, $o->status, $o->requestedCentre?->code ?? '-'));
            }

            return self::SUCCESS;
        }

        $handle = (string) $this->argument('handle');
        $officer = AuctionOfficer::where('uuid', $handle)->orWhere('officerNo', strtoupper($handle))->first();
        if (! $officer) {
            $user = User::where('email', $handle)->orWhere('username', $handle)->first();
            $officer = $user ? AuctionOfficer::where('userId', $user->id)->first() : null;
        }
        if (! $officer) {
            $this->error("No auction officer matches {$handle}.");

            return self::INVALID;
        }

        $admin = User::where('role', UserRole::SYSTEM_USER)->first() ?? User::first();
        $centre = null;
        if ($this->option('centre')) {
            $key = (string) $this->option('centre');
            $centre = AuctionCentre::where('uuid', $key)->orWhere('code', strtoupper($key))->first();
            if (! $centre) {
                $this->error("No centre matches {$key}.");

                return self::INVALID;
            }
        }

        if ($this->option('approve')) {
            $service->verify($officer, $admin, AuctionOfficer::VERIFIED, $this->option('notes'), $centre, (string) $this->option('role'));
            $this->info("{$officer->fullName} approved.");
        } elseif ($this->option('reject')) {
            $service->verify($officer, $admin, AuctionOfficer::REJECTED, $this->option('notes'));
            $this->info("{$officer->fullName} rejected.");
        } else {
            $this->line(json_encode($officer->toWire(true), JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
