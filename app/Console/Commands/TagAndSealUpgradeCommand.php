<?php

namespace App\Console\Commands;

use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\IdentifierType;
use App\Models\SubjectIdentifier;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class TagAndSealUpgradeCommand extends Command
{
    protected $signature = 'tag-and-seal:upgrade
        {release : Upgrade release name, currently dynamic-v1}
        {--dry-run : Inspect readiness without changing business data}
        {--resume= : Resume an existing run UUID}
        {--verify-only= : Verify an existing run UUID without writing business data}
        {--cutover : Enable dynamic reads after successful reconciliation}
        {--rollback= : Disable dynamic reads for an existing run UUID}
        {--chunk=250 : Backfill chunk size}
        {--backup= : Verified backup or snapshot reference}
        {--production-confirmation= : Must equal the application name in production}';

    protected $description = 'Safely upgrade Tag & Seal to the dynamic-v1 platform with resumable checkpoints';

    public function handle(DynamicV1Installer $installer): int
    {
        if ($this->argument('release') !== 'dynamic-v1') {
            $this->error('Unknown upgrade release. Supported release: dynamic-v1.');

            return self::INVALID;
        }
        if ($this->option('rollback')) {
            return $this->rollback((string) $this->option('rollback'));
        }
        if ($this->option('dry-run')) {
            return $this->dryRun();
        }
        if (app()->environment('production')) {
            if (! $this->option('backup') || $this->option('production-confirmation') !== config('app.name')) {
                $this->error('Production requires --backup and the exact --production-confirmation application name.');

                return self::FAILURE;
            }
        }

        Artisan::call('migrate', ['--force' => true]);
        if ($this->option('verify-only')) {
            return $this->verify((string) $this->option('verify-only'));
        }

        $runUuid = (string) ($this->option('resume') ?: Str::uuid());
        $run = DB::table('platform_upgrade_runs')->where('uuid', $runUuid)->first();
        if (! $run) {
            DB::table('platform_upgrade_runs')->insert([
                'uuid' => $runUuid,
                'release' => 'dynamic-v1',
                'environment' => app()->environment(),
                'state' => 'running',
                'backup_reference' => $this->option('backup'),
                'source_schema_version' => 'legacy',
                'target_schema_version' => 'dynamic-v1',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $this->step($runUuid, 'install_definitions', fn () => count($installer->install()));
            $this->step($runUuid, 'backfill_subjects', fn () => $this->backfillSubjects($runUuid, $installer));
            $this->step($runUuid, 'backfill_identifiers', fn () => $this->backfillIdentifiers($runUuid, $installer));
            $this->step($runUuid, 'install_legacy_adapters', fn () => $installer->installLegacyHistoryAdapters());
            $this->step($runUuid, 'backfill_events', fn () => $this->backfillEvents($runUuid, $installer));
            $this->step($runUuid, 'reconcile_dynamic_v1', fn () => $this->reconcile($runUuid));
        } catch (Throwable $error) {
            DB::table('platform_upgrade_runs')->where('uuid', $runUuid)->update([
                'state' => 'failed', 'updated_at' => now(),
            ]);
            $this->error($error->getMessage());
            $this->line("Resume with: php artisan tag-and-seal:upgrade dynamic-v1 --resume=$runUuid");

            return self::FAILURE;
        }

        $critical = DB::table('migration_anomalies')->where('run_uuid', $runUuid)->where('severity', 'critical')->where('resolution_status', 'open')->count();
        if ($this->option('cutover') && $critical === 0) {
            DB::table('platform_feature_flags')->updateOrInsert(['key' => 'dynamic_reads'], [
                'enabled' => true, 'value_json' => json_encode(['runUuid' => $runUuid]), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        DB::table('platform_upgrade_runs')->where('uuid', $runUuid)->update([
            'state' => $critical === 0 ? 'completed' : 'blocked',
            'completed_at' => now(),
            'final_report_json' => json_encode(['criticalAnomalies' => $critical, 'cutover' => (bool) $this->option('cutover') && $critical === 0]),
            'updated_at' => now(),
        ]);
        $this->info("Upgrade run: $runUuid");
        $this->info($critical === 0 ? 'Dynamic-v1 upgrade completed.' : "Cutover blocked by $critical critical anomaly/anomalies.");

        return $critical === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dryRun(): int
    {
        $checks = [
            'legacy_livestocks' => Schema::hasTable('livestocks') ? DB::table('livestocks')->count() : null,
            'legacy_species' => Schema::hasTable('species') ? DB::table('species')->count() : null,
            'dynamic_schema_installed' => Schema::hasTable('animal_subjects'),
            'database_driver' => DB::getDriverName(),
        ];
        $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info('Dry run complete. No business data was changed.');

        return in_array(null, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function step(string $runUuid, string $code, callable $callback): void
    {
        $existing = DB::table('platform_upgrade_steps')->where('run_uuid', $runUuid)->where('step_code', $code)->first();
        if ($existing?->state === 'completed') {
            $this->line("Skipping completed step: $code");

            return;
        }
        DB::table('platform_upgrade_steps')->updateOrInsert(['run_uuid' => $runUuid, 'step_code' => $code], [
            'state' => 'running', 'attempts' => ((int) ($existing?->attempts ?? 0)) + 1,
            'started_at' => now(), 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now(),
        ]);
        try {
            $processed = (int) $callback();
            DB::table('platform_upgrade_steps')->where('run_uuid', $runUuid)->where('step_code', $code)->update([
                'state' => 'completed', 'processed_count' => $processed, 'completed_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);
            $this->info("Completed: $code ($processed)");
        } catch (Throwable $error) {
            DB::table('platform_upgrade_steps')->where('run_uuid', $runUuid)->where('step_code', $code)->update([
                'state' => 'failed', 'failed_count' => DB::raw('failed_count + 1'), 'last_error' => $error->getMessage(), 'updated_at' => now(),
            ]);
            throw $error;
        }
    }

    private function backfillSubjects(string $runUuid, DynamicV1Installer $installer): int
    {
        $processed = 0;
        DB::table('livestocks')->orderBy('id')->chunk((int) $this->option('chunk'), function ($rows) use (&$processed, $runUuid, $installer): void {
            foreach ($rows as $legacy) {
                $species = DB::table('species')->where('id', $legacy->speciesId)->first();
                $code = $this->canonicalSpeciesCode((string) ($species->name ?? ''));
                $canonical = AnimalSpecies::query()->where('code', $code)->first();
                $blueprint = $canonical ? AnimalBlueprint::query()->where('species_uuid', $canonical->uuid)->where('version', 1)->first() : null;
                if (! $canonical || ! $blueprint) {
                    $this->anomaly($runUuid, 'backfill_subjects', 'critical', 'SPECIES_MAPPING_MISSING', 'livestocks', (string) $legacy->id, ['legacySpecies' => $species->name ?? null]);

                    continue;
                }
                $uuid = $legacy->uuid ?: $installer->uuid('legacy/livestocks/'.$legacy->id);
                AnimalSubject::withTrashed()->updateOrCreate(['uuid' => $uuid], [
                    'farm_uuid' => $legacy->farmUuid,
                    'species_uuid' => $canonical->uuid,
                    'blueprint_uuid' => $blueprint->uuid,
                    'blueprint_version' => 1,
                    'unit_type' => 'individual',
                    'display_name' => $legacy->name,
                    'birth_or_start_date' => $legacy->dateOfBirth,
                    'sex_code' => in_array($legacy->gender, ['male', 'female'], true) ? $legacy->gender : 'unknown',
                    'lifecycle_status' => $this->normalizeLifecycle((string) $legacy->status),
                    'attributes_json' => ['legacyId' => $legacy->id, 'identificationNumber' => $legacy->identificationNumber, 'breedId' => $legacy->breedId, 'livestockTypeId' => $legacy->livestockTypeId, 'weightAsOnRegistration' => $legacy->weightAsOnRegistration],
                    'revision' => max(1, (int) ($legacy->revision ?? 1)),
                    'deleted_at' => $legacy->deleted_at ?? null,
                    'deleted_by_uuid' => $legacy->deleted_by_uuid ?? null,
                    'delete_reason' => $legacy->delete_reason ?? null,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
                DB::table('legacy_record_mappings')->updateOrInsert([
                    'source_table' => 'livestocks', 'source_key' => (string) $legacy->id, 'target_type' => 'animal_subject',
                ], ['source_uuid' => $legacy->uuid, 'target_uuid' => $uuid, 'target_version' => 1, 'updated_at' => now(), 'created_at' => now()]);
                $processed++;
            }
        });

        return $processed;
    }

    private function backfillIdentifiers(string $runUuid, DynamicV1Installer $installer): int
    {
        $processed = 0;
        $pairedType = IdentifierType::query()->where('code', 'paired_rfid_barcode')->firstOrFail();
        $visualType = IdentifierType::query()->where('code', 'visual_ear_tag')->firstOrFail();
        DB::table('livestocks')->orderBy('id')->chunk((int) $this->option('chunk'), function ($rows) use (&$processed, $runUuid, $installer, $pairedType, $visualType): void {
            foreach ($rows as $legacy) {
                $mapping = DB::table('legacy_record_mappings')->where('source_table', 'livestocks')->where('source_key', (string) $legacy->id)->where('target_type', 'animal_subject')->first();
                if (! $mapping) {
                    continue;
                }
                $barcode = trim((string) ($legacy->barcodeTagId ?? ''));
                $rfid = trim((string) ($legacy->rfidTagId ?? ''));
                if ($barcode !== '' || $rfid !== '') {
                    if ($barcode === '' || $rfid === '' || strcasecmp($barcode, $rfid) !== 0) {
                        $this->anomaly($runUuid, 'backfill_identifiers', 'critical', 'PAIRED_TAG_MISMATCH', 'livestocks', (string) $legacy->id, ['barcode' => $barcode, 'rfid' => $rfid]);
                    } else {
                        $processed += $this->upsertIdentifier($installer, $mapping->target_uuid, $pairedType->uuid, $barcode, 'paired');
                    }
                }
                $visual = trim((string) ($legacy->identificationNumber ?? ''));
                if ($visual !== '') {
                    $processed += $this->upsertIdentifier($installer, $mapping->target_uuid, $visualType->uuid, $visual, 'visual');
                }
            }
        });

        return $processed;
    }

    private function upsertIdentifier(DynamicV1Installer $installer, string $subjectUuid, string $typeUuid, string $value, string $kind): int
    {
        $normalized = mb_strtoupper(trim($value));
        $uuid = $installer->uuid("legacy/identifier/$kind/$subjectUuid/$normalized");
        $collision = SubjectIdentifier::withTrashed()->where('identifier_type_uuid', $typeUuid)->where('normalized_value', $normalized)->where('subject_uuid', '!=', $subjectUuid)->exists();
        if ($collision) {
            return 0;
        }
        SubjectIdentifier::withTrashed()->updateOrCreate(['uuid' => $uuid], [
            'subject_uuid' => $subjectUuid, 'identifier_type_uuid' => $typeUuid,
            'normalized_value' => $normalized, 'display_value' => trim($value),
            'status' => 'active', 'assigned_at' => now(), 'revision' => 1,
        ]);

        return 1;
    }

    private function reconcile(string $runUuid): int
    {
        $expected = DB::table('livestocks')->count();
        $actual = DB::table('legacy_record_mappings')->where('source_table', 'livestocks')->where('target_type', 'animal_subject')->count();
        $passed = $expected === $actual;
        DB::table('reconciliation_results')->updateOrInsert(['run_uuid' => $runUuid, 'check_code' => 'livestock_subject_count'], [
            'entity_family' => 'animal_subjects', 'expected_count' => $expected, 'actual_count' => $actual,
            'passed' => $passed, 'details_json' => json_encode(['rule' => 'one target per legacy livestock']), 'updated_at' => now(), 'created_at' => now(),
        ]);
        if (! $passed) {
            $this->anomaly($runUuid, 'reconcile', 'critical', 'SUBJECT_COUNT_MISMATCH', null, null, compact('expected', 'actual'));
        }

        $eventExpected = 0;
        foreach ($this->legacyEventTables() as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'livestockUuid')) {
                $eventExpected += DB::table($table)->whereNotNull('livestockUuid')->count();
            }
        }
        $eventActual = DB::table('legacy_record_mappings')->where('target_type', 'event_record')->count();
        $eventsPassed = $eventExpected === $eventActual;
        DB::table('reconciliation_results')->updateOrInsert(['run_uuid' => $runUuid, 'check_code' => 'legacy_event_count'], [
            'entity_family' => 'event_records', 'expected_count' => $eventExpected, 'actual_count' => $eventActual,
            'passed' => $eventsPassed, 'details_json' => json_encode(['tables' => $this->legacyEventTables()]), 'updated_at' => now(), 'created_at' => now(),
        ]);
        if (! $eventsPassed) {
            $this->anomaly($runUuid, 'reconcile', 'critical', 'EVENT_COUNT_MISMATCH', null, null, ['expected' => $eventExpected, 'actual' => $eventActual]);
        }

        return 2;
    }

    private function backfillEvents(string $runUuid, DynamicV1Installer $installer): int
    {
        $processed = 0;
        foreach ($this->legacyEventTables() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'livestockUuid')) {
                continue;
            }
            DB::table($table)->whereNotNull('livestockUuid')->orderBy('id')->chunk((int) $this->option('chunk'), function ($rows) use (&$processed, $runUuid, $installer, $table): void {
                foreach ($rows as $row) {
                    $subject = AnimalSubject::withTrashed()->where('uuid', $row->livestockUuid)->first();
                    if (! $subject) {
                        $this->anomaly($runUuid, 'backfill_events', 'critical', 'EVENT_SUBJECT_MISSING', $table, (string) $row->id, ['livestockUuid' => $row->livestockUuid]);

                        continue;
                    }
                    $definition = DB::table('event_definitions')
                        ->where('blueprint_uuid', $subject->blueprint_uuid)
                        ->where('blueprint_version', $subject->blueprint_version)
                        ->where('code', 'legacy_history')
                        ->first();
                    if (! $definition) {
                        throw new \RuntimeException("Legacy event adapter missing for blueprint {$subject->blueprint_uuid}");
                    }
                    $sourceKey = (string) $row->id;
                    $eventUuid = $installer->uuid("legacy/event/$table/$sourceKey");
                    $payload = (array) $row;
                    $payload['_legacyTable'] = $table;
                    $payload['_legacyId'] = $row->id;
                    $occurredAt = $row->eventDate ?? $row->created_at ?? now();
                    DB::table('event_records')->updateOrInsert(['uuid' => $eventUuid], [
                        'farm_uuid' => $row->farmUuid ?? $subject->farm_uuid,
                        'subject_uuid' => $subject->uuid,
                        'event_definition_uuid' => $definition->uuid,
                        'event_code' => 'legacy.'.str($table)->replace('_', '.')->toString(),
                        'behavior_code' => $definition->behavior_code,
                        'event_definition_version' => 1,
                        'form_uuid' => $definition->form_uuid,
                        'form_version' => 1,
                        'occurred_at' => $occurredAt,
                        'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'record_status' => 'confirmed',
                        'idempotency_uuid' => $installer->uuid("legacy/event-idempotency/$table/$sourceKey"),
                        'revision' => 1,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);
                    SyncChangeRecorder::eventByUuid($eventUuid);
                    DB::table('legacy_record_mappings')->updateOrInsert([
                        'source_table' => $table, 'source_key' => $sourceKey, 'target_type' => 'event_record',
                    ], ['source_uuid' => $row->uuid ?? null, 'target_uuid' => $eventUuid, 'target_version' => 1, 'updated_at' => now(), 'created_at' => now()]);
                    $processed++;
                }
            });
        }

        return $processed;
    }

    private function legacyEventTables(): array
    {
        return [
            'feedings', 'weight_changes', 'dewormings', 'treatments', 'vaccinations',
            'disposals', 'birth_events', 'aborted_pregnancies', 'milkings', 'pregnancies',
            'inseminations', 'dryoffs', 'transfers', 'teeth_clippings', 'tail_dockings',
            'iron_injections', 'livestock_markings', 'stage_changes', 'prepuce_conditions',
            'identification_events',
        ];
    }

    private function verify(string $runUuid): int
    {
        if (! DB::table('platform_upgrade_runs')->where('uuid', $runUuid)->exists()) {
            $this->error('Upgrade run not found.');

            return self::FAILURE;
        }
        $this->reconcile($runUuid);
        $failed = DB::table('reconciliation_results')->where('run_uuid', $runUuid)->where('passed', false)->count();
        $this->line(json_encode(DB::table('reconciliation_results')->where('run_uuid', $runUuid)->get(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function rollback(string $runUuid): int
    {
        if (Schema::hasTable('platform_feature_flags')) {
            DB::table('platform_feature_flags')->updateOrInsert(['key' => 'dynamic_reads'], ['enabled' => false, 'value_json' => json_encode(['rolledBackRunUuid' => $runUuid]), 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->info('Dynamic reads disabled. No migrated or legacy records were deleted.');

        return self::SUCCESS;
    }

    private function anomaly(string $runUuid, string $step, string $severity, string $code, ?string $table, ?string $key, array $details): void
    {
        DB::table('migration_anomalies')->insert([
            'run_uuid' => $runUuid, 'step_code' => $step, 'severity' => $severity,
            'error_code' => $code, 'source_table' => $table, 'source_key' => $key,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR), 'resolution_status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function canonicalSpeciesCode(string $name): string
    {
        $normalized = str($name)->lower()->ascii()->toString();

        return match (true) {
            str_contains($normalized, 'cattle'), str_contains($normalized, 'cow') => 'cattle',
            str_contains($normalized, 'pig'), str_contains($normalized, 'swine') => 'pig',
            str_contains($normalized, 'goat') => 'goat',
            str_contains($normalized, 'sheep') => 'sheep',
            str_contains($normalized, 'chicken'), str_contains($normalized, 'poultry') => 'chicken',
            str_contains($normalized, 'rabbit') => 'rabbit',
            str_contains($normalized, 'fish') => 'fish',
            str_contains($normalized, 'bee') => 'bee',
            default => str($normalized)->slug('_')->toString(),
        };
    }

    private function normalizeLifecycle(string $status): string
    {
        return match ($status) {
            'notActive', 'not-active', 'not_active', 'inactive' => 'inactive',
            'dead', 'sold', 'transferred', 'disposed' => $status,
            default => 'active',
        };
    }
}
