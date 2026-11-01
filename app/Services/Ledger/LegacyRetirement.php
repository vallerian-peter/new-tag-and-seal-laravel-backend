<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Last step of the migration: archive a retired family's legacy tables and report
 * which legacy tables can go. Nothing here drops a table; the drop script it writes
 * is for a person to review and run.
 */
class LegacyRetirement
{
    public const DIRECTORY = 'legacy-archive';

    /** Legacy tables that stay: the livestock register and what it, farms and finance still use. */
    private const KEEP_REASONS = [
        'stages' => 'Livestock current stage (livestocks.stageId); mirrored to the Portal lookup for events',
        'breeds' => 'Livestock breed (livestocks.breedId)',
        'livestock_types' => 'Species catalogue binding for livestock and blueprints',
        'species' => 'Livestock species (livestocks.speciesId)',
    ];

    /** @return array{run: string, tables: array<int, array{table: string, rows: int, path: string, sha256: string}>} */
    public function archive(string $family, ?string $actorUuid = null): array
    {
        if (LegacyBridge::authority($family) !== 'retired') {
            throw new RuntimeException("Retire $family in the Portal (Ledger Migration) before archiving it.");
        }
        $run = (string) Str::uuid();
        $folder = self::DIRECTORY."/$family/".now()->format('Ymd_His');
        $disk = Storage::disk('local');
        $tables = [];
        foreach (LegacyCatalog::tablesFor($family) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $lines = '';
            $rows = 0;
            DB::table($table)->orderBy('id')->chunk(1000, function ($chunk) use (&$lines, &$rows): void {
                foreach ($chunk as $row) {
                    $lines .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                    $rows++;
                }
            });
            $path = "$folder/$table.jsonl.gz";
            $content = gzencode($lines, 9);
            $disk->put($path, $content);
            $sha = hash('sha256', $content);
            if (hash('sha256', (string) $disk->get($path)) !== $sha) {
                throw new RuntimeException("Archive of $table did not verify.");
            }
            DB::table('legacy_archives')->insert([
                'run_uuid' => $run, 'family' => $family, 'source_table' => $table, 'row_count' => $rows,
                'max_id' => DB::table($table)->max('id'), 'path' => $path, 'sha256' => $sha, 'actor_uuid' => $actorUuid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $tables[] = ['table' => $table, 'rows' => $rows, 'path' => $path, 'sha256' => $sha];
        }
        $disk->put("$folder/manifest.json", json_encode(['run' => $run, 'family' => $family, 'archivedAt' => now()->toIso8601String(), 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['run' => $run, 'tables' => $tables];
    }

    /** The decoded rows of an archived table, for restore or audit. */
    public function read(string $path): \Generator
    {
        foreach (explode("\n", (string) gzdecode((string) Storage::disk('local')->get($path))) as $line) {
            if ($line !== '') {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
    }

    /**
     * Every legacy log and reference table with its fate, rows, dependants and
     * whether it may be dropped yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inventory(): array
    {
        $dependants = $this->dependants();
        $report = [];
        foreach (LegacyCatalog::FAMILIES as $family) {
            $authority = LegacyBridge::authority($family);
            foreach (LegacyCatalog::tablesFor($family) as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $rows = DB::table($table)->count();
                $archive = DB::table('legacy_archives')->where('source_table', $table)->orderByDesc('id')->first();
                $archived = $archive && (int) $archive->row_count === $rows && (int) $archive->max_id === (int) DB::table($table)->max('id');
                $blockers = array_values(array_filter([
                    $authority === 'retired' ? null : "family is on $authority (retire it first)",
                    $archived ? null : 'no current archive (run: php artisan tag-and-seal:ledger archive '.$family.')',
                ]));
                $report[] = [
                    'table' => $table, 'fate' => 'remove', 'family' => $family, 'authority' => $authority, 'rows' => $rows,
                    'replacement' => 'event_records ('.implode(', ', array_column(LegacyCatalog::table($table)['events'], 'code')).')',
                    'dependants' => $dependants[$table] ?? [], 'archived' => $archived, 'droppable' => $blockers === [], 'blockers' => $blockers,
                ];
            }
        }
        $logTables = array_column($report, 'table');
        $lookupUsers = [];
        foreach ($logTables as $logTable) {
            foreach (LegacyCatalog::table($logTable)['fields'] as $field) {
                if (isset($field['lookup'])) {
                    $lookupUsers[$field['lookup']][] = $logTable;
                }
            }
        }
        foreach (LegacyCatalog::referenceTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $users = array_values(array_unique([...($dependants[$table] ?? []), ...($lookupUsers[$table] ?? [])]));
            sort($users);
            $outside = array_values(array_diff($users, $logTables));
            $families = collect($report)->whereIn('table', $users)->pluck('family')->unique()->values()->all();
            $retired = $users !== [] && collect($report)->whereIn('table', $users)->every(fn ($row) => $row['droppable']);
            $keep = isset(self::KEEP_REASONS[$table]) || $outside !== [];
            $report[] = [
                'table' => $table, 'fate' => $keep ? 'keep' : 'move', 'family' => implode(', ', $families) ?: null, 'authority' => null,
                'rows' => DB::table($table)->count(),
                'replacement' => DB::table('lookup_sets')->where('legacy_table', $table)->pluck('code')->implode(', ') ?: 'lookup set',
                'dependants' => $users, 'archived' => null,
                'droppable' => ! $keep && $retired,
                'blockers' => match (true) {
                    $keep => [self::KEEP_REASONS[$table] ?? 'still used by '.implode(', ', $outside)],
                    $users === [] => ['no known users; review by hand'],
                    $retired => [],
                    default => ['a log table that uses it is not droppable yet'],
                },
            ];
        }

        return $report;
    }

    /** Writes (never runs) the SQL that would drop every droppable table, and returns its path. */
    public function writeDropScript(array $inventory): string
    {
        $droppable = array_values(array_filter($inventory, fn ($row) => $row['droppable']));
        $sql = ['-- Tag & Seal legacy table removal. Generated '.now()->toIso8601String().'.',
            '-- Review and run only with written approval, after a full database backup.',
            '-- Archives: '.Storage::disk('local')->path(self::DIRECTORY).'/<family>/<timestamp>/<table>.jsonl.gz', ''];
        if ($droppable === []) {
            $sql[] = '-- Nothing is droppable yet: every legacy family must be retired and archived first.';
        }
        foreach ($this->foreignKeysInto(array_column($droppable, 'table')) as $key) {
            $sql[] = "ALTER TABLE `{$key->source}` DROP FOREIGN KEY `{$key->name}`;";
        }
        foreach ($droppable as $row) {
            $sql[] = "DROP TABLE IF EXISTS `{$row['table']}`; -- {$row['fate']}, {$row['rows']} rows";
        }
        $path = self::DIRECTORY.'/drop-legacy-tables.sql';
        Storage::disk('local')->put($path, implode("\n", $sql)."\n");

        return Storage::disk('local')->path($path);
    }

    /** @return array<string, array<int, string>> table => tables with a foreign key to it */
    private function dependants(): array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return DB::table('information_schema.key_column_usage')->where('table_schema', DB::getDatabaseName())->whereNotNull('referenced_table_name')
            ->selectRaw('table_name as source, referenced_table_name as target')->get()
            ->groupBy('target')
            ->map(fn ($rows) => $rows->pluck('source')->unique()->sort()->values()->all())
            ->all();
    }

    /** Foreign keys from tables that stay into tables being dropped; the columns themselves are kept. */
    private function foreignKeysInto(array $tables): array
    {
        if ($tables === [] || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return DB::table('information_schema.key_column_usage')->where('table_schema', DB::getDatabaseName())
            ->whereIn('referenced_table_name', $tables)->whereNotIn('table_name', $tables)
            ->selectRaw('table_name as source, constraint_name as name')->distinct()->get()->all();
    }
}
