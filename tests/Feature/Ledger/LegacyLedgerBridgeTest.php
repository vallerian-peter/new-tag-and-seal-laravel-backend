<?php

namespace Tests\Feature\Ledger;

use App\Enums\UserRole;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\Bill;
use App\Models\BirthEvent;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\FinanceExpense;
use App\Models\FinanceIncome;
use App\Models\FormDefinition;
use App\Models\Livestock;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\WeightChange;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Ledger\AppVersionPolicy;
use App\Services\Ledger\FamilyReconciler;
use App\Services\Ledger\LedgerEventInstaller;
use App\Services\Ledger\LegacyCatalog;
use App\Services\Ledger\LegacyLookups;
use App\Services\Ledger\LegacyRetiredException;
use App\Services\Ledger\LegacyRetirement;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Phases 4 and 5: legacy log tables and the event ledger stay in step for every family. */
class LegacyLedgerBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const FARM = 'farm-ledger-1';

    private array $headers;

    private Livestock $cow;

    private Livestock $bull;

    private Livestock $sow;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::disableForeignKeyConstraints();
        LegacyLookups::flush();
        app(DynamicV1Installer::class)->install();
        AnimalSpecies::query()->where('code', 'cattle')->update(['legacy_livestock_type_id' => 1]);
        AnimalSpecies::query()->where('code', 'pig')->update(['legacy_livestock_type_id' => 2]);
        DB::table('livestock_types')->insert([['id' => 1, 'name' => 'Cattle'], ['id' => 2, 'name' => 'Swine']]);
        DB::table('species')->insert([['id' => 1, 'name' => 'Cattle'], ['id' => 2, 'name' => 'Pig']]);
        $this->reference('breeds', [[1, 'Friesian', 1], [2, 'Large White', 2]]);
        $this->reference('birth_types', [[1, 'Normal Calving', 1], [2, 'Normal Farrowing', 2], [3, 'Assisted', null]]);
        $this->reference('stages', [[1, 'Calf', 1], [2, 'Heifer', 1], [3, 'Piglet', 2], [4, 'Weaner', 2]]);
        foreach (LegacyCatalog::referenceTables() as $table) {
            if (DB::table($table)->doesntExist()) {
                $this->reference($table, [[1, 'First option', null], [2, 'Second option', null]]);
            }
        }
        app(LedgerEventInstaller::class)->install();

        $admin = User::factory()->create(['role' => UserRole::SYSTEM_USER]);
        $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('ledger')->plainTextToken];
        $this->cow = $this->livestock('Daisy', 1, 'female');
        $this->bull = $this->livestock('Bruno', 1, 'male');
        $this->sow = $this->livestock('Peppa', 2, 'female');
    }

    public function test_installer_publishes_legacy_events_with_roles_lookups_and_family(): void
    {
        $manifest = app(ConfigurationSyncService::class)->latestManifest();
        $cattle = AnimalBlueprint::query()->where('code', 'cattle.standard')->value('uuid');
        $pig = AnimalBlueprint::query()->where('code', 'pig.standard')->value('uuid');
        $events = collect($manifest['events']);

        $calving = $events->first(fn ($event) => $event['code'] === 'record_calving' && $event['blueprintUuid'] === $cattle);
        $this->assertSame(['births', 'calving', 'record_birth'], [$calving['family'], $calving['legacyLogType'], $calving['behavior']]);
        $this->assertNotNull($events->first(fn ($event) => $event['code'] === 'record_farrowing' && $event['blueprintUuid'] === $pig));
        $this->assertNull($events->first(fn ($event) => $event['code'] === 'record_calving' && $event['blueprintUuid'] === $pig));
        $this->assertSame('health', $events->first(fn ($event) => $event['code'] === 'record_vaccination' && $event['blueprintUuid'] === $cattle)['family']);

        $form = collect($manifest['forms'])->first(fn ($form) => $calving['form'] === $form['uuid'].'@'.$form['version']);
        $fields = collect($form['schema']['sections'][0]['fields'])->keyBy('key');
        $this->assertSame(['born_alive', 'aliveCount'], [$fields['born_alive']['role'], $fields['born_alive']['legacy_column']]);
        $this->assertSame('legacy.birth_types.cattle', $fields['birth_type']['lookup_code']);
        $this->assertSame('offspring', $fields['offspring']['role']);

        $options = collect(collect($manifest['lookups'])->firstWhere('code', 'legacy.birth_types.cattle')['options']);
        $this->assertEqualsCanonicalizing([1, 3], $options->pluck('legacyId')->all());
        $this->assertSame('normal_calving', $options->firstWhere('legacyId', 1)['value']);

        $versions = FormDefinition::query()->count();
        app(LedgerEventInstaller::class)->install();
        $this->assertSame($versions, FormDefinition::query()->count(), 'Reinstalling an unchanged catalog adds no form versions.');
    }

    public function test_legacy_birth_is_copied_into_the_ledger_and_follows_edits_and_deletes(): void
    {
        $birth = BirthEvent::query()->create([
            'uuid' => 'legacy-birth-1', 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid,
            'eventType' => 'calving', 'eventDate' => '2026-09-01 08:00:00', 'startDate' => '2026-09-01',
            'birthTypeId' => 1, 'aliveCount' => 2, 'deadCount' => 1, 'totalBorn' => 3, 'status' => 'active',
        ]);
        $record = $this->recordFor('birth_events', $birth->id);
        $this->assertSame('record_calving', $record->event_code);
        $this->assertSame(['born_alive' => 2, 'born_dead' => 1, 'birth_type' => 'normal_calving', 'labour_started_on' => '2026-09-01'], $record->payload_json);
        $this->assertSame('2026-09-01', $record->occurred_at->toDateString());
        $this->assertDatabaseHas('subject_reproduction_state', ['subject_uuid' => $this->cow->uuid, 'total_born_alive' => 2]);

        $birth->update(['aliveCount' => 3]);
        $record->refresh();
        $this->assertSame(3, $record->payload_json['born_alive']);
        $this->assertSame(2, $record->revision);
        $this->assertDatabaseHas('subject_reproduction_state', ['subject_uuid' => $this->cow->uuid, 'total_born_alive' => 3]);

        $calf = $this->livestock('Calf', 1, 'female', ['motherUuid' => $this->cow->uuid, 'birthEventUuid' => 'legacy-birth-1']);
        $this->assertSame($record->uuid, AnimalSubject::query()->where('uuid', $calf->uuid)->value('attributes_json')['birth_event_uuid']);

        $birth->delete();
        $this->assertSame('voided', $record->refresh()->record_status);
        $this->assertDatabaseMissing('subject_reproduction_state', ['subject_uuid' => $this->cow->uuid]);
    }

    public function test_shadow_capture_and_legacy_row_converge_in_either_order(): void
    {
        $definition = $this->definition($this->cow, 'record_weight');

        // Legacy row first, then the configured capture with the same uuid.
        $first = (string) Str::uuid();
        WeightChange::query()->create(['uuid' => $first, 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid, 'oldWeight' => '300', 'newWeight' => '320', 'eventDate' => '2026-09-02 09:00:00']);
        $this->submit($this->cow, $definition, ['new_weight' => 320], $first)->assertCreated()->assertJsonPath('data.uuid', $first);
        $this->assertSame(1, EventRecord::query()->where('uuid', $first)->count());

        // Capture first, then the legacy row: the bridge adopts the captured event.
        $second = (string) Str::uuid();
        $this->submit($this->cow, $definition, ['new_weight' => 330], $second)->assertCreated();
        $legacy = WeightChange::query()->create(['uuid' => $second, 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid, 'oldWeight' => '320', 'newWeight' => '331', 'eventDate' => '2026-09-03 09:00:00']);
        $this->assertSame(1, EventRecord::query()->where('uuid', $second)->count());
        $this->assertSame($second, $this->recordFor('weight_changes', $legacy->id)->uuid);
        $this->assertSame(331, EventRecord::query()->where('uuid', $second)->value('payload_json')['new_weight']);
        $this->assertSame(331, AnimalSubject::query()->where('uuid', $this->cow->uuid)->value('attributes_json')['current_weight']);

        $this->submit($this->bull, $this->definition($this->bull, 'record_weight'), ['new_weight' => 1], $first)
            ->assertUnprocessable()->assertJsonValidationErrors('uuid');
    }

    public function test_ledger_authority_writes_legacy_rows_offspring_and_voids(): void
    {
        $calving = $this->definition($this->cow, 'record_calving');
        $this->submit($this->cow, $calving, ['born_alive' => 1, 'birth_type' => 'normal_calving'])->assertCreated();
        $this->assertDatabaseCount('birth_events', 0);

        $this->authority('births', 'ledger');
        $uuid = $this->submit($this->cow, $calving, [
            'born_alive' => 1, 'born_dead' => 0, 'birth_type' => 'normal_calving',
            'offspring' => [['display_name' => 'Bella', 'sex_code' => 'female', 'weight' => 30]],
        ])->assertCreated()->json('data.uuid');
        $row = DB::table('birth_events')->where('uuid', $uuid)->first();
        $this->assertSame(['calving', 1, 1, 1, 'active'], [$row->eventType, (int) $row->aliveCount, (int) $row->totalBorn, (int) $row->birthTypeId, $row->status]);
        $this->assertSame(substr((string) $row->eventDate, 0, 10), (string) $row->startDate);
        $calf = Livestock::query()->where('birthEventUuid', $uuid)->firstOrFail();
        $this->assertSame(['Bella', $this->cow->uuid, 'female', 30.0], [$calf->name, $calf->motherUuid, $calf->gender, (float) $calf->weightAsOnRegistration]);
        $this->assertSame($calf->id, AnimalSubject::query()->where('uuid', $calf->uuid)->value('attributes_json')['legacyId']);

        $this->authority('movement', 'ledger');
        $disposal = $this->submit($this->bull, $this->definition($this->bull, 'record_disposal'), [
            'disposal_type' => 'first_option', 'reasons' => 'Sold at market', 'remarks' => 'Good price', 'sale_price' => 900,
        ])->assertCreated()->json('data.uuid');
        $this->assertSame('notActive', $this->bull->refresh()->status);
        $this->assertSame('inactive', AnimalSubject::query()->where('uuid', $this->bull->uuid)->value('lifecycle_status'));
        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$disposal/void", ['reason' => 'Entered on the wrong animal'])->assertOk();
        $this->assertSame('failed', DB::table('disposals')->where('uuid', $disposal)->value('status'));
    }

    public function test_every_family_round_trips_through_the_legacy_tables_and_reconciles(): void
    {
        foreach (LegacyCatalog::FAMILIES as $family) {
            $this->authority($family, 'ledger');
        }
        $submitted = 0;
        foreach (LegacyCatalog::tables() as $table => $spec) {
            foreach ($spec['events'] as $event) {
                $animal = match (true) {
                    $event['sex'] === 'male' => $this->bull,
                    in_array('cattle', $event['species'], true) => $this->cow,
                    default => $this->sow,
                };
                $definition = $this->definition($animal, $event['code']);
                $form = FormDefinition::query()->where('uuid', $definition->form_uuid)->where('version', $definition->form_version)->firstOrFail();
                $this->submit($animal, $definition, $this->samplePayload($form->schema_json))->assertCreated();
                $this->assertSame(1, DB::table($table)->where('uuid', EventRecord::query()->latest('id')->value('uuid'))->count(), "$table has the legacy copy of {$event['code']}");
                $submitted++;
            }
        }
        $this->assertSame(20, $submitted);
        foreach (LegacyCatalog::FAMILIES as $family) {
            $result = app(FamilyReconciler::class)->run($family);
            $this->assertTrue($result['passed'], "$family: ".json_encode(collect($result['checks'])->where('passed', false)->values()));
        }
    }

    public function test_reconciliation_detects_drift_and_repair_restores_it(): void
    {
        $birth = BirthEvent::query()->create([
            'uuid' => (string) Str::uuid(), 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid,
            'eventType' => 'calving', 'startDate' => '2026-09-01', 'birthTypeId' => 1, 'aliveCount' => 1, 'status' => 'active',
        ]);
        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'births'])->assertSuccessful();

        DB::table('birth_events')->where('id', $birth->id)->update(['aliveCount' => 4]);
        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'births'])->assertFailed();
        $failed = DB::table('reconciliation_results')->where('entity_family', 'births')->where('passed', false)->pluck('check_code')->all();
        $this->assertSame(['birth_events.values'], $failed);

        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'births', '--repair' => true])->assertSuccessful();
        $this->assertSame(4, $this->recordFor('birth_events', $birth->id)->payload_json['born_alive']);
    }

    public function test_finance_rows_follow_their_log_to_the_ledger_event(): void
    {
        $logUuid = 'vaccination-1766058674312710-813500939';
        $bill = Bill::query()->create([
            'uuid' => (string) Str::uuid(), 'billNo' => 'BILL-1', 'farmUuid' => self::FARM, 'subjectType' => 'vaccination',
            'subjectUuid' => $logUuid, 'quantity' => 1, 'amount' => '5000', 'status' => 'pending',
        ]);
        $expense = FinanceExpense::query()->create([
            'uuid' => (string) Str::uuid(), 'sourceType' => 'bill', 'sourceUuid' => $bill->uuid, 'farmUuid' => self::FARM,
            'billNo' => 'BILL-1', 'subjectType' => 'vaccination', 'quantity' => 1, 'unitAmount' => 5000, 'totalAmount' => 5000, 'status' => 'pending',
        ]);
        $this->assertNull(DB::table('bills')->where('id', $bill->id)->value('eventRecordUuid'));

        $vaccination = Vaccination::query()->create([
            'uuid' => $logUuid, 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid, 'vaccinationNo' => 'V-1', 'diseaseId' => 1, 'status' => 'completed',
        ]);
        $event = $this->recordFor('vaccinations', $vaccination->id)->uuid;
        $this->assertNotSame($logUuid, $event);
        $this->assertSame($event, DB::table('bills')->where('id', $bill->id)->value('eventRecordUuid'));
        $this->assertSame($event, DB::table('finance_expenses')->where('id', $expense->id)->value('eventRecordUuid'));

        $income = FinanceIncome::query()->create([
            'uuid' => (string) Str::uuid(), 'sourceType' => 'sale', 'sourceUuid' => $logUuid, 'farmUuid' => self::FARM,
            'subjectType' => 'Sale', 'quantity' => 1, 'unitAmount' => 10, 'totalAmount' => 10, 'status' => 'received',
        ]);
        $this->assertSame($event, DB::table('finance_incomes')->where('id', $income->id)->value('eventRecordUuid'));

        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'health'])->assertSuccessful();
        DB::table('bills')->update(['eventRecordUuid' => null]);
        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'health'])->assertFailed();
        $this->assertSame(['health.finance_links'], DB::table('reconciliation_results')->where('passed', false)->distinct()->pluck('check_code')->all());
        $this->artisan('tag-and-seal:ledger', ['action' => 'reconcile', 'family' => 'health', '--repair' => true])->assertSuccessful();
        $this->assertSame($event, DB::table('bills')->where('id', $bill->id)->value('eventRecordUuid'));
    }

    public function test_reference_list_drift_is_detected_and_repaired_without_changing_option_codes(): void
    {
        $this->assertTrue(app(FamilyReconciler::class)->run('health')['passed']);
        $code = DB::table('lookup_options')->where('legacy_id', 1)->whereIn('lookup_set_uuid', DB::table('lookup_sets')->where('code', 'legacy.diseases')->pluck('uuid'))->value('code');

        DB::table('diseases')->where('id', 1)->update(['name' => 'Renamed outside the Portal']);
        DB::table('diseases')->insert(['id' => 9, 'name' => 'Added outside the Portal']);
        $result = app(FamilyReconciler::class)->run('health');
        $this->assertFalse(collect($result['checks'])->firstWhere('code', 'health.reference_lists')['passed']);

        $this->assertTrue(app(FamilyReconciler::class)->run('health', repair: true)['passed']);
        $options = DB::table('lookup_options')->whereIn('lookup_set_uuid', DB::table('lookup_sets')->where('code', 'legacy.diseases')->pluck('uuid'))->get()->keyBy('legacy_id');
        $this->assertSame($code, $options[1]->code);
        $this->assertSame('Renamed outside the Portal', $options[1]->label);
        $this->assertSame('added_outside_the_portal', $options[9]->code);
    }

    public function test_minimum_app_version_is_enforced_at_login_and_published(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422);
        DB::table('platform_feature_flags')->insert([
            'key' => AppVersionPolicy::FLAG, 'enabled' => true, 'value_json' => json_encode(['version' => '1.2.0']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [])->assertStatus(426)->assertJson(['code' => 'APP_UPDATE_REQUIRED', 'minimumAppVersion' => '1.2.0']);
        $this->withHeader('X-App-Version', '1.1.9')->postJson('/api/auth/extension-officer/login', [])->assertStatus(426);
        $this->withHeader('X-App-Version', '1.2.0+14')->postJson('/api/auth/login', [])->assertStatus(422);
        $this->withHeader('X-App-Version', '1.10.0')->postJson('/api/auth/login', [])->assertStatus(422);
        $this->assertSame('1.2.0', app(ConfigurationSyncService::class)->compile(99)['minimumAppVersion']);
    }

    public function test_retired_family_is_read_only_archived_and_inventoried_without_dropping(): void
    {
        $vaccination = Vaccination::query()->create([
            'uuid' => (string) Str::uuid(), 'farmUuid' => self::FARM, 'livestockUuid' => $this->cow->uuid, 'vaccinationNo' => 'V-1', 'diseaseId' => 1, 'status' => 'completed',
        ]);
        $this->expectArchiveRefused('health');
        $this->authority('health', 'retired');

        try {
            $vaccination->update(['vaccinationNo' => 'V-2']);
            $this->fail('A retired legacy table accepted a write.');
        } catch (LegacyRetiredException $error) {
            $this->assertSame('vaccinations', $error->table);
        }
        $this->assertSame('V-1', DB::table('vaccinations')->where('id', $vaccination->id)->value('vaccinationNo'));

        $definition = $this->definition($this->cow, 'record_vaccination');
        $schema = FormDefinition::query()->where('uuid', $definition->form_uuid)->where('version', $definition->form_version)->first()->schema_json;
        $this->submit($this->cow, $definition, $this->samplePayload($schema))->assertCreated();
        $this->assertSame(1, DB::table('vaccinations')->count());
        $this->assertTrue(app(FamilyReconciler::class)->run('health')['passed']);

        Storage::fake('local');
        $this->artisan('tag-and-seal:ledger', ['action' => 'archive', 'family' => 'health'])->assertSuccessful();
        $archive = DB::table('legacy_archives')->where('source_table', 'vaccinations')->first();
        $this->assertSame(1, (int) $archive->row_count);
        $rows = iterator_to_array(app(LegacyRetirement::class)->read($archive->path));
        $this->assertSame('V-1', $rows[0]['vaccinationNo']);

        $inventory = collect(app(LegacyRetirement::class)->inventory())->keyBy('table');
        $this->assertTrue($inventory['vaccinations']['droppable']);
        $this->assertSame('remove', $inventory['vaccinations']['fate']);
        $this->assertFalse($inventory['birth_events']['droppable']);
        $this->assertSame('keep', $inventory['stages']['fate']);
        $this->assertSame('move', $inventory['diseases']['fate']);

        $this->artisan('tag-and-seal:ledger', ['action' => 'inventory'])->assertSuccessful();
        $script = Storage::disk('local')->get(LegacyRetirement::DIRECTORY.'/drop-legacy-tables.sql');
        $this->assertStringContainsString('DROP TABLE IF EXISTS `vaccinations`;', $script);
        $this->assertStringNotContainsString('`birth_events`', $script);
        $this->assertTrue(Schema::hasTable('vaccinations'));
    }

    private function expectArchiveRefused(string $family): void
    {
        $this->artisan('tag-and-seal:ledger', ['action' => 'archive', 'family' => $family])->assertFailed();
        $this->assertSame(0, DB::table('legacy_archives')->count());
    }

    private function samplePayload(array $schema): array
    {
        $payload = [];
        foreach ($schema['sections'][0]['fields'] as $field) {
            if ($field['component'] === 'repeatable_group') {
                continue;
            }
            $options = isset($field['lookup_code'])
                ? DB::table('lookup_options')->join('lookup_sets', 'lookup_sets.uuid', '=', 'lookup_options.lookup_set_uuid')
                    ->where('lookup_sets.code', $field['lookup_code'])->orderBy('lookup_options.sort_order')->pluck('lookup_options.code')->all()
                : array_column($field['options'] ?? [], 'value');
            $payload[$field['key']] = match ($field['component']) {
                'integer' => 2,
                'decimal', 'measurement' => 12.5,
                'date' => '2026-10-01',
                'date_time' => '2026-10-01T08:00:00+00:00',
                'single_select' => $field['default_value'] ?? $options[0],
                'multi_select' => [$options[0]],
                default => 'Sample '.$field['key'],
            };
        }

        return $payload;
    }

    private function submit(Livestock $animal, EventDefinition $definition, array $payload, ?string $uuid = null): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/subjects/{$animal->uuid}/events", array_filter([
            'uuid' => $uuid,
            'eventDefinitionUuid' => $definition->uuid,
            'idempotencyUuid' => (string) Str::uuid(),
            'occurredAt' => '2026-10-01T08:00:00Z',
            'baseRevision' => AnimalSubject::query()->where('uuid', $animal->uuid)->value('revision'),
            'payload' => $payload,
        ], fn ($value) => $value !== null));
    }

    private function definition(Livestock $animal, string $code): EventDefinition
    {
        $subject = AnimalSubject::query()->where('uuid', $animal->uuid)->firstOrFail();

        return EventDefinition::query()->where('blueprint_uuid', $subject->blueprint_uuid)->where('code', $code)->firstOrFail();
    }

    private function recordFor(string $table, int $id): EventRecord
    {
        $uuid = DB::table('legacy_record_mappings')->where('source_table', $table)->where('source_key', (string) $id)->where('target_type', 'event_record')->value('target_uuid');

        return EventRecord::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function authority(string $family, string $value): void
    {
        DB::table('platform_feature_flags')->updateOrInsert(['key' => "family.$family.authority"], [
            'enabled' => true, 'value_json' => json_encode(['authority' => $value]), 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    private function livestock(string $name, int $type, string $gender, array $extra = []): Livestock
    {
        return Livestock::query()->create($extra + [
            'uuid' => (string) Str::uuid(), 'farmUuid' => self::FARM, 'livestockTypeId' => $type, 'speciesId' => $type,
            'breedId' => $type, 'name' => $name, 'dateOfBirth' => '2022-01-01', 'gender' => $gender,
            'status' => 'active', 'isIdentified' => true,
        ]);
    }

    /** @param  array<int, array{0: int, 1: string, 2: ?int}>  $rows */
    private function reference(string $table, array $rows): void
    {
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        foreach ($rows as [$id, $name, $type]) {
            $row = ['id' => $id, 'name' => $name];
            if ($columns->has('livestockTypeId')) {
                $row['livestockTypeId'] = $type;
            }
            foreach ($columns as $column => $meta) {
                if (! array_key_exists($column, $row) && ! $meta['nullable'] && $meta['default'] === null && ! $meta['auto_increment']) {
                    $row[$column] = str_contains(strtolower($meta['type_name']), 'int') ? 1 : $name;
                }
            }
            DB::table($table)->insert($row);
        }
    }
}
