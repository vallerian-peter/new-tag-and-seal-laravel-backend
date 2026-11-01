<?php

namespace App\Console\Commands;

use App\Services\Ledger\FamilyReconciler;
use App\Services\Ledger\LedgerEventInstaller;
use App\Services\Ledger\LegacyBridge;
use App\Services\Ledger\LegacyCatalog;
use App\Services\Ledger\LegacyRetirement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LedgerCommand extends Command
{
    protected $signature = 'tag-and-seal:ledger
        {action : install, reconcile, status, archive or inventory}
        {family? : births, health, production, breeding, growth, movement; omit for all}
        {--repair : Re-bridge legacy rows and mirror livestock before reconciling}
        {--json : Print the inventory as JSON}';

    protected $description = 'Install ledger events for legacy log families, reconcile them with the legacy tables, archive retired families and report the legacy table inventory';

    public function handle(LedgerEventInstaller $installer, FamilyReconciler $reconciler, LegacyRetirement $retirement): int
    {
        $family = $this->argument('family');
        if ($family !== null && ! in_array($family, LegacyCatalog::FAMILIES, true)) {
            $this->error('Unknown family. Use one of: '.implode(', ', LegacyCatalog::FAMILIES));

            return self::INVALID;
        }
        $families = $family ? [$family] : LegacyCatalog::FAMILIES;

        return match ($this->argument('action')) {
            'install' => $this->install($installer, $family ? [$family] : null),
            'reconcile' => $this->reconcile($reconciler, $families),
            'status' => $this->status($families),
            'archive' => $this->archive($retirement, $family),
            'inventory' => $this->inventory($retirement),
            default => $this->invalid(),
        };
    }

    private function archive(LegacyRetirement $retirement, ?string $family): int
    {
        if (! $family) {
            $this->error('Name the family to archive.');

            return self::INVALID;
        }
        try {
            $result = $retirement->archive($family);
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info("Archived $family (run {$result['run']}) to ".Storage::disk('local')->path(LegacyRetirement::DIRECTORY."/$family").'. Legacy tables were not changed.');
        $this->table(['Table', 'Rows', 'SHA-256'], array_map(fn ($table) => [$table['table'], $table['rows'], $table['sha256']], $result['tables']));

        return self::SUCCESS;
    }

    private function inventory(LegacyRetirement $retirement): int
    {
        $inventory = $retirement->inventory();
        $script = $retirement->writeDropScript($inventory);
        if ($this->option('json')) {
            $this->line(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->table(['Table', 'Fate', 'Family', 'Authority', 'Rows', 'Replaced by', 'Dependants', 'Droppable', 'Blockers'], array_map(fn ($row) => [
            $row['table'], $row['fate'], $row['family'] ?? '-', $row['authority'] ?? '-', $row['rows'], $row['replacement'],
            implode(', ', $row['dependants']) ?: '-', $row['droppable'] ? 'yes' : 'no', implode('; ', $row['blockers']) ?: '-',
        ], $inventory));
        $this->line("Drop script written (not run): $script");

        return self::SUCCESS;
    }

    private function install(LedgerEventInstaller $installer, ?array $families): int
    {
        $result = $installer->install($families);
        $this->info("Installed {$result['events']} events and {$result['lookupOptions']} lookup options; configuration revision {$result['revision']}.");

        return self::SUCCESS;
    }

    private function reconcile(FamilyReconciler $reconciler, array $families): int
    {
        $failed = false;
        foreach ($families as $family) {
            $result = $reconciler->run($family, (bool) $this->option('repair'));
            $failed = $failed || ! $result['passed'];
            $this->line(($result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>')." $family (run {$result['run']})");
            $this->table(['Check', 'Expected', 'Actual', 'Result'], collect($result['checks'])->map(fn ($check) => [
                $check['code'], $check['expected'], $check['actual'], $check['passed'] ? 'pass' : 'fail',
            ])->all());
            if ($this->output->isVerbose()) {
                foreach (collect($result['checks'])->where('passed', false) as $check) {
                    $this->line($check['code'].': '.json_encode($check['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function status(array $families): int
    {
        $rows = [];
        foreach ($families as $family) {
            $latest = DB::table('reconciliation_results')->where('entity_family', $family)->orderByDesc('id')->value('run_uuid');
            $failed = $latest ? DB::table('reconciliation_results')->where('run_uuid', $latest)->where('passed', false)->count() : null;
            $rows[] = [$family, LegacyBridge::authority($family), $latest ?? '-', $latest ? ($failed === 0 ? 'passed' : "$failed failed") : 'never run'];
        }
        $this->table(['Family', 'Authority', 'Latest run', 'Result'], $rows);

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use install, reconcile, status, archive or inventory.');

        return self::INVALID;
    }
}
