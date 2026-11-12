<?php

namespace App\Console\Commands;

use App\Models\AuctionCentre;
use App\Models\AuctionCentreMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create or update an auction centre and attach auctioneers from the shell,
 * for servers without the admin portal screen yet.
 *
 *   php artisan auction:centre "Pugu Livestock Market" --code=PUGU --member=dalali@example.com
 *   php artisan auction:centre --code=PUGU --member=clerk1 --role=clerk
 *   php artisan auction:centre --list
 */
class AuctionCentreCommand extends Command
{
    protected $signature = 'auction:centre
        {name? : Centre name (creates the centre when the code is new)}
        {--code= : Short unique code such as PUGU (derived from the name when omitted)}
        {--member=* : Email or username of an account to attach}
        {--role=auctioneer : Role for attached members: manager, auctioneer or clerk}
        {--address= : Physical address}
        {--phone= : Contact phone}
        {--inactive : Mark the centre inactive}
        {--list : List centres and their members}';

    protected $description = 'Create or update an auction centre and attach members';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listCentres();
        }

        $name = $this->argument('name');
        $code = strtoupper((string) ($this->option('code') ?: ($name ? Str::slug(Str::limit($name, 12, ''), '') : '')));
        if ($code === '') {
            $this->error('Give a centre name or --code.');

            return self::INVALID;
        }

        $centre = AuctionCentre::where('code', $code)->first();
        if (! $centre && ! $name) {
            $this->error("No centre with code {$code}. Pass a name to create it.");

            return self::INVALID;
        }

        $attrs = array_filter([
            'name' => $name,
            'address' => $this->option('address'),
            'phone' => $this->option('phone'),
            'status' => $this->option('inactive') ? AuctionCentre::STATUS_INACTIVE : null,
        ], fn ($v) => $v !== null);
        if ($centre) {
            $centre->fill($attrs)->save();
            $this->info("Updated centre {$centre->code} — {$centre->name}");
        } else {
            $centre = AuctionCentre::create(array_merge(['code' => $code], $attrs));
            $this->info("Created centre {$centre->code} — {$centre->name} ({$centre->uuid})");
        }

        $role = (string) $this->option('role');
        if (! in_array($role, AuctionCentre::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', AuctionCentre::ROLES));

            return self::INVALID;
        }
        foreach ((array) $this->option('member') as $handle) {
            $user = User::where('email', $handle)->orWhere('username', $handle)->first();
            if (! $user) {
                $this->warn("  ! No account found for {$handle}, skipped");

                continue;
            }
            AuctionCentreMember::updateOrCreate(
                ['centreId' => $centre->id, 'userId' => $user->id],
                ['role' => $role, 'status' => AuctionCentreMember::STATUS_ACTIVE],
            );
            $this->line("  + {$user->username} <{$user->email}> attached as {$role}");
        }

        return self::SUCCESS;
    }

    private function listCentres(): int
    {
        $centres = AuctionCentre::with('members.user')->orderBy('name')->get();
        if ($centres->isEmpty()) {
            $this->line('No auction centres yet.');

            return self::SUCCESS;
        }
        foreach ($centres as $c) {
            $this->info("{$c->code}  {$c->name}  [{$c->status}]  {$c->uuid}");
            foreach ($c->members as $m) {
                $this->line(sprintf('    %-12s %-24s %s', $m->role, $m->user?->username, $m->status));
            }
        }

        return self::SUCCESS;
    }
}
