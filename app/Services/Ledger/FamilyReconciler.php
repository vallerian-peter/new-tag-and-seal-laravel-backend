<?php

namespace App\Services\Ledger;

use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\EventRecord;
use App\Models\FormDefinition;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compares a family's legacy rows with their ledger events, column by column.
 * A family may only move to `ledger` while its latest run passed.
 */
class FamilyReconciler
{
    private const SAMPLE = 25;

    public function __construct(
        private readonly LegacyBridge $bridge,
        private readonly LegacySubjectMirror $subjects,
        private readonly DynamicV1Installer $installer,
        private readonly FinanceLinker $finance,
        private readonly LegacyLookups $lookups,
        private readonly ConfigurationSyncService $configuration,
    ) {}

    /** @return array{run: string, family: string, passed: bool, checks: array<int, array<string, mixed>>} */
    public function run(string $family, bool $repair = false): array
    {
        if (! in_array($family, LegacyCatalog::FAMILIES, true)) {
            throw new \InvalidArgumentException("Unknown family $family");
        }
        if ($repair) {
            $this->repair($family);
        }
        $run = (string) Str::uuid();
        $checks = [$this->subjectsCheck()];
        foreach (LegacyCatalog::tablesFor($family) as $table) {
            if (Schema::hasTable($table)) {
                array_push($checks, ...$this->tableChecks($table));
            }
        }
        $references = $this->lookups->audit(collect(LegacyCatalog::tablesFor($family))
            ->flatMap(fn ($table) => array_filter(array_column(LegacyCatalog::table($table)['fields'], 'lookup')))->unique()->values()->all());
        $checks[] = $this->check("$family.reference_lists", $references['expected'], $references['expected'] - count($references['drifted']), ['drifted' => array_slice($references['drifted'], 0, self::SAMPLE)]);
        $checks[] = $this->ledgerOnlyCheck($family);
        $finance = $this->finance->audit(LegacyCatalog::tablesFor($family));
        $checks[] = $this->check("$family.finance_links", $finance['expected'], $finance['expected'] - count($finance['broken']), ['broken' => array_slice($finance['broken'], 0, self::SAMPLE)]);
        if ($family === 'births') {
            $checks[] = $this->offspringCheck();
        }
        $checks[] = $this->anomalyCheck($family);

        foreach ($checks as $check) {
            DB::table('reconciliation_results')->insert([
                'run_uuid' => $run,
                'check_code' => $check['code'],
                'entity_family' => $family,
                'expected_count' => $check['expected'],
                'actual_count' => $check['actual'],
                'expected_hash' => $check['expectedHash'] ?? null,
                'actual_hash' => $check['actualHash'] ?? null,
                'passed' => $check['passed'],
                'details_json' => json_encode($check['details'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['run' => $run, 'family' => $family, 'passed' => collect($checks)->every('passed'), 'checks' => $checks];
    }

    /** Re-bridges every legacy row and mirrors every livestock row of the family. */
    public function repair(string $family): int
    {
        $count = $this->lookups->mirror();
        DB::table('livestocks')->orderBy('id')->chunk(500, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                $this->subjects->mirror((array) $row) && $count++;
            }
        });
        foreach (LegacyCatalog::tablesFor($family) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->whereNotNull('livestockUuid')->orderBy('id')->chunk(500, function ($rows) use ($table, &$count): void {
                foreach ($rows as $row) {
                    $this->bridge->safely('repair', $table, $row->id, fn () => $this->bridge->fromLegacy($table, (array) $row)) && $count++;
                }
            });
            DB::table('migration_anomalies')->where('run_uuid', $this->installer->uuid(LegacyBridge::ANOMALY_RUN))
                ->where('source_table', $table)->where('resolution_status', 'open')->where('created_at', '<', now()->subSecond())
                ->update(['resolution_status' => 'resolved', 'resolution_note' => 'Re-bridged by reconciliation repair', 'updated_at' => now()]);
        }

        $this->configuration->publishRevision();

        return $count + $this->finance->linkAll();
    }

    private function subjectsCheck(): array
    {
        $expected = DB::table('livestocks')->count();
        $missing = DB::table('livestocks as l')->leftJoin('animal_subjects as s', 's.uuid', '=', 'l.uuid')->whereNull('s.id')->pluck('l.id');

        return $this->check('subjects.mirrored', $expected, $expected - $missing->count(), ['missingLivestockIds' => $missing->take(self::SAMPLE)->all()]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tableChecks(string $table): array
    {
        $spec = LegacyCatalog::table($table);
        $speciesBySubject = [];
        $expectedRows = [];
        $actualRows = [];
        $unmapped = [];
        $mismatched = [];
        $outOfScope = 0;
        $total = 0;
        $formKeysByVersion = [];

        DB::table($table)->whereNotNull('livestockUuid')->orderBy('id')->chunk(500, function ($rows) use ($table, $spec, &$speciesBySubject, &$expectedRows, &$actualRows, &$unmapped, &$mismatched, &$outOfScope, &$total, &$formKeysByVersion): void {
            foreach ($rows as $row) {
                $row = (array) $row;
                $speciesCode = $speciesBySubject[$row['livestockUuid']] ??= (string) AnimalSpecies::query()
                    ->where('uuid', AnimalSubject::withTrashed()->where('uuid', $row['livestockUuid'])->value('species_uuid'))->value('code');
                $event = LegacyCatalog::eventFor($spec, $speciesCode, $row);
                if (! $event) {
                    $outOfScope++;

                    continue;
                }
                $total++;
                $mapping = DB::table('legacy_record_mappings')->where('source_table', $table)->where('source_key', (string) $row['id'])->where('target_type', 'event_record')->first();
                $record = $mapping ? EventRecord::query()->where('uuid', $mapping->target_uuid)->first() : null;
                if (! $record) {
                    $unmapped[] = $row['id'];

                    continue;
                }
                $expected = $this->normalize([
                    'event' => $event['code'],
                    'subject' => $row['livestockUuid'],
                    'date' => substr((string) ($row['eventDate'] ?? $row['created_at'] ?? ''), 0, 10),
                    'voided' => isset($spec['void']['status']) && ($row['status'] ?? null) === $spec['void']['status'],
                    'payload' => $this->bridge->comparable($spec, $this->bridge->payloadFromRow($spec, $speciesCode, $row)),
                    'unknownKeys' => [],
                ]);
                $formKeys = $formKeysByVersion[$record->form_uuid.'@'.$record->form_version] ??= collect(FormDefinition::query()
                    ->where('uuid', $record->form_uuid)->where('version', $record->form_version)->first()?->schema_json['sections'] ?? [])
                    ->flatMap(fn ($section) => array_column($section['fields'] ?? [], 'key'))->all();
                $legacyKeys = array_flip(array_column(array_filter($spec['fields'], fn ($field) => $field['column'] || isset($field['constant'])), 'key'));
                $actual = $this->normalize([
                    'event' => $record->event_code,
                    'subject' => $record->subject_uuid,
                    'date' => $row['eventDate'] ?? $row['created_at'] ?? null ? $record->occurred_at->toDateString() : '',
                    'voided' => $record->record_status === 'voided',
                    'payload' => $this->bridge->comparable($spec, array_intersect_key($record->payload_json ?? [], $legacyKeys)),
                    'unknownKeys' => array_values(array_diff(array_keys($record->payload_json ?? []), $formKeys)),
                ]);
                $expectedRows[] = $expected;
                $actualRows[] = $actual;
                if ($expected !== $actual) {
                    $mismatched[] = ['id' => $row['id'], 'expected' => json_decode($expected, true), 'actual' => json_decode($actual, true)];
                }
            }
        });

        return [
            $this->check("$table.mapped", $total, $total - count($unmapped), ['unmappedIds' => array_slice($unmapped, 0, self::SAMPLE), 'outOfScope' => $outOfScope]),
            $this->check("$table.values", $total - count($unmapped), $total - count($unmapped) - count($mismatched), ['mismatches' => array_slice($mismatched, 0, self::SAMPLE)],
                hash('sha256', implode("\n", $expectedRows)), hash('sha256', implode("\n", $actualRows))),
        ];
    }

    /** Ledger events of legacy-backed animals that have no legacy row. */
    private function ledgerOnlyCheck(string $family): array
    {
        $codes = collect(LegacyCatalog::tablesFor($family))->flatMap(fn ($table) => array_column(LegacyCatalog::table($table)['events'], 'code'))->all();
        $orphans = DB::table('event_records as e')
            ->join('livestocks as l', 'l.uuid', '=', 'e.subject_uuid')
            ->leftJoin('legacy_record_mappings as m', fn ($join) => $join->on('m.target_uuid', '=', 'e.uuid')->where('m.target_type', '=', 'event_record'))
            ->whereIn('e.event_code', $codes)->where('e.record_status', '!=', 'voided')->whereNull('m.id')
            ->when(LegacyBridge::authority($family) === 'retired', fn ($query) => $query->where('e.created_at', '<', DB::table('platform_feature_flags')->where('key', "family.$family.authority")->value('updated_at')))
            ->pluck('e.uuid');

        return $this->check("$family.ledger_only", 0, $orphans->count(), ['eventUuids' => $orphans->take(self::SAMPLE)->all(), 'rule' => 'every ledger event of a legacy animal has a legacy row']);
    }

    private function offspringCheck(): array
    {
        $rows = DB::table('livestocks')->whereNotNull('birthEventUuid')->get(['uuid', 'birthEventUuid']);
        $broken = [];
        foreach ($rows as $row) {
            $event = DB::table('legacy_record_mappings')->where('source_table', 'birth_events')->where('source_uuid', $row->birthEventUuid)->value('target_uuid') ?? $row->birthEventUuid;
            $linked = AnimalSubject::withTrashed()->where('uuid', $row->uuid)->first()?->attributes_json['birth_event_uuid'] ?? null;
            if ($linked !== $event) {
                $broken[] = $row->uuid;
            }
        }

        return $this->check('births.offspring_links', $rows->count(), $rows->count() - count($broken), ['unlinkedOffspring' => array_slice($broken, 0, self::SAMPLE)]);
    }

    private function anomalyCheck(string $family): array
    {
        $open = DB::table('migration_anomalies')->where('run_uuid', $this->installer->uuid(LegacyBridge::ANOMALY_RUN))
            ->whereIn('source_table', LegacyCatalog::tablesFor($family))->where('resolution_status', 'open')->get(['source_table', 'source_key', 'error_code']);

        return $this->check("$family.bridge_errors", 0, $open->count(), ['open' => $open->take(self::SAMPLE)->all()]);
    }

    private function check(string $code, int $expected, int $actual, array $details, ?string $expectedHash = null, ?string $actualHash = null): array
    {
        return [
            'code' => $code, 'expected' => $expected, 'actual' => $actual, 'passed' => $expected === $actual,
            'expectedHash' => $expectedHash, 'actualHash' => $actualHash, 'details' => $details,
        ];
    }

    private function normalize(array $value): string
    {
        $walk = function (mixed $item) use (&$walk): mixed {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                return array_map($walk, $item);
            }

            return is_int($item) || is_float($item) ? (string) (float) $item : $item;
        };

        return json_encode($walk($value), JSON_THROW_ON_ERROR);
    }
}
