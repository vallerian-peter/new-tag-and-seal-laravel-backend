<?php

namespace App\Console\Commands;

use App\Services\FarmerNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenumberFarmers extends Command
{
    protected $signature = 'farmers:renumber {--apply : Save the new numbers; without it the command only previews}';

    protected $description = 'Give farmers with old-style numbers a FMR-<4 digits>-<6 digits> number';

    public function handle(): int
    {
        $legacy = DB::table('farmers')->select('id', 'farmerNo')->orderBy('id')->get()
            ->reject(fn ($farmer) => preg_match(FarmerNumberGenerator::PATTERN, (string) $farmer->farmerNo));

        if ($legacy->isEmpty()) {
            $this->info('Every farmer already has a FMR-<4 digits>-<6 digits> number.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        foreach ($legacy as $farmer) {
            $next = FarmerNumberGenerator::generate();
            if ($apply) {
                DB::table('farmers')->where('id', $farmer->id)->update(['farmerNo' => $next, 'updated_at' => now()]);
            }
            $rows[] = [$farmer->id, $farmer->farmerNo, $next];
        }

        $this->table(['Farmer id', 'Old number', 'New number'], $rows);
        $apply
            ? $this->info(count($rows).' farmer numbers updated.')
            : $this->warn('Preview only. Run again with --apply to save these numbers.');

        return self::SUCCESS;
    }
}
