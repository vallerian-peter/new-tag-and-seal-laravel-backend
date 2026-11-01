<?php

namespace App\Console\Commands;

use App\Services\Dynamic\SubjectProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildProjectionsCommand extends Command
{
    protected $signature = 'tag-and-seal:projections
        {--check : Report differences between the summary tables and the event ledger without changing anything}
        {--subject= : Limit to one subject UUID}';

    protected $description = 'Rebuild (or verify) reproduction and production summaries from the event ledger';

    public function handle(SubjectProjector $projector): int
    {
        $subjects = $this->option('subject')
            ? collect([$this->option('subject')])
            : DB::table('event_records')->whereNotNull('behavior_code')->distinct()->pluck('subject_uuid')
                ->merge(DB::table('subject_reproduction_state')->pluck('subject_uuid'))
                ->merge(DB::table('subject_production_daily')->distinct()->pluck('subject_uuid'))
                ->unique()->values();

        if ($this->option('check')) {
            $differences = $subjects->flatMap(fn (string $uuid) => $projector->differences($uuid));
            foreach ($differences as $difference) {
                $this->line($difference);
            }
            $this->info("Checked {$subjects->count()} subjects; ".$differences->count().' differences.');

            return $differences->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        foreach ($subjects as $uuid) {
            DB::transaction(fn () => $projector->rebuildSubject($uuid));
        }
        $this->info("Rebuilt summaries for {$subjects->count()} subjects.");

        return self::SUCCESS;
    }
}
