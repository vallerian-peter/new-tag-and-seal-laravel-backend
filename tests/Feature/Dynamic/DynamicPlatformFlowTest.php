<?php

namespace Tests\Feature\Dynamic;

use App\Enums\UserRole;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSubject;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\User;
use App\Services\Dynamic\BlueprintPublisher;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\FormSchemaValidator;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PublishesConfiguration;
use Tests\TestCase;

class DynamicPlatformFlowTest extends TestCase
{
    use PublishesConfiguration;
    use RefreshDatabase;

    public function test_new_blueprint_event_versions_work_for_existing_animals_without_rewriting_registration(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SYSTEM_USER, 'platform_role' => 'superAdmin']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('workflow-version')->plainTextToken];
        $species = $this->draftSpecies('versioned_events');
        $registration = $this->publishedForm('versioned.registration', 'registration', [$this->field('display_name', 'text', 'string', true)]);
        $form = $this->publishedForm('versioned.observation', 'event', [$this->field('notes', 'text', 'string', true)]);
        $source = $this->draftBlueprint($species, 'versioned.standard', $registration);
        $originalEvent = $this->draftEvent($source, 'observation', $form, 'record_only');
        $this->publishBlueprint($source);
        $animal = $this->withHeaders($headers)->postJson('/api/v1/dynamic/subjects', [
            'farmUuid' => 'existing-farm', 'blueprintUuid' => $source, 'blueprintVersion' => 1,
            'unitType' => 'individual', 'sexCode' => 'female', 'payload' => ['display_name' => 'Existing animal'],
            'idempotencyUuid' => (string) Str::uuid(),
        ])->assertCreated()->json('data.uuid');
        $publisher = app(BlueprintPublisher::class);
        $draft = $publisher->cloneDraft(AnimalBlueprint::where('uuid', $source)->firstOrFail(), $admin);
        $copied = EventDefinition::where('blueprint_uuid', $draft->uuid)->sole();
        $this->assertNotSame($originalEvent, $copied->uuid);
        $this->assertSame($form, $copied->form_uuid);
        $publisher->publish($draft, $admin);
        $command = ['uuid' => (string) Str::uuid(), 'eventDefinitionUuid' => $copied->uuid,
            'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 1, 'payload' => ['notes' => 'New workflow on existing animal']];
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$animal/events", $command)
            ->assertCreated()->assertJsonPath('data.event_definition_version', 2);
        $subject = AnimalSubject::where('uuid', $animal)->firstOrFail();
        $this->assertSame($source, $subject->blueprint_uuid);
        $this->assertSame(1, $subject->blueprint_version);
        // Another workflow, even for the same species, cannot attach its event.
        $other = AnimalBlueprint::create([
            'species_uuid' => $species, 'code' => 'versioned.unrelated', 'version' => 3, 'status' => 'draft',
            'registration_form_uuid' => $registration, 'registration_form_version' => 1,
            'settings_json' => ['subjectModes' => ['individual']],
        ]);
        $otherEvent = EventDefinition::create([
            'blueprint_uuid' => $other->uuid, 'blueprint_version' => 3, 'code' => 'other_observation',
            'name' => 'Other observation', 'form_uuid' => $form, 'form_version' => 1,
            'behavior_code' => 'record_only', 'status' => 'draft',
        ])->uuid;
        $this->publishBlueprint($other->uuid);
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$animal/events", [
            'uuid' => (string) Str::uuid(), 'idempotencyUuid' => (string) Str::uuid(),
            'eventDefinitionUuid' => $otherEvent, 'baseRevision' => 2, 'payload' => ['notes' => 'Not allowed'],
        ])->assertUnprocessable()->assertJsonValidationErrors('event');
        $publisher->retire(AnimalBlueprint::where('uuid', $source)->firstOrFail(), $admin);
        $lineages = $this->withHeaders($headers)->getJson('/api/v1/config/manifest')->assertOk()->json('data.blueprintLineages');
        $this->assertTrue(collect($lineages)->contains('uuid', $source));
    }

    public function test_bulk_event_commands_validate_each_animal_and_retry_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SYSTEM_USER, 'platform_role' => 'superAdmin']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('bulk-events')->plainTextToken];
        $species = $this->draftSpecies('bulk_test');
        $registration = $this->publishedForm('bulk.registration', 'registration', [$this->field('display_name', 'text', 'string', true)]);
        $form = $this->publishedForm('bulk.weight', 'event', [$this->field('weight', 'decimal', 'decimal', true, ['min' => 0])]);
        $blueprint = $this->draftBlueprint($species, 'bulk.standard', $registration);
        $event = $this->draftEvent($blueprint, 'bulk_weight', $form, 'update_weight', [
            'eligibility_rule_json' => ['fact' => 'subject.sex_code', 'operator' => 'equals', 'value' => 'female'],
        ]);
        $this->publishBlueprint($blueprint);
        $commands = [];
        foreach (['female', 'male', 'female'] as $index => $sex) {
            $animal = $this->withHeaders($headers)->postJson('/api/v1/dynamic/subjects', [
                'farmUuid' => 'bulk-farm', 'blueprintUuid' => $blueprint, 'blueprintVersion' => 1,
                'unitType' => 'individual', 'sexCode' => $sex, 'payload' => ['display_name' => 'Animal '.$index],
                'idempotencyUuid' => (string) Str::uuid(),
            ])->assertCreated()->json('data.uuid');
            $commands[] = [$animal, [
                'uuid' => (string) Str::uuid(), 'eventDefinitionUuid' => $event,
                'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 1,
                'occurredAt' => '2026-10-28T10:20:30.123Z', 'payload' => ['weight' => 40 + $index],
            ]];
        }
        foreach ($commands as $index => [$animal, $command]) {
            $response = $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$animal/events", $command);
            if ($index === 1) {
                $response->assertUnprocessable()->assertJsonValidationErrors('event');
            } else {
                $response->assertCreated()->assertJsonPath('data.subject_uuid', $animal);
                $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$animal/events", $command)
                    ->assertCreated()->assertJsonPath('data.uuid', $command['uuid']);
            }
        }
        $this->assertSame(2, EventRecord::count());
        $this->assertSame(1, AnimalSubject::where('uuid', $commands[1][0])->firstOrFail()->revision);
    }

    public function test_published_configuration_lets_mobile_register_and_record_event(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SYSTEM_USER, 'platform_role' => 'superAdmin']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('dynamic-test')->plainTextToken];

        $speciesUuid = $this->draftSpecies('test_animal', null, 'both', ['group_display_name' => 'Group']);
        $registrationFormUuid = $this->publishedForm('test.registration', 'registration', [
            $this->field('display_name', 'text', 'string', true),
        ]);
        $eventFormUuid = $this->publishedForm('test.weight', 'event', [
            $this->field('weight', 'decimal', 'decimal', true, ['min' => 0]),
        ]);
        $blueprintUuid = $this->draftBlueprint($speciesUuid, 'test_animal.standard', $registrationFormUuid);
        $eventDefinitionUuid = $this->draftEvent($blueprintUuid, 'record_weight', $eventFormUuid, 'update_weight', [
            'name' => 'Record weight',
            'eligibility_rule_json' => ['all' => [['fact' => 'subject.lifecycle_status', 'operator' => 'equals', 'value' => 'active']]],
        ]);
        $this->publishBlueprint($blueprintUuid);

        $this->withHeaders($headers)->getJson('/api/v1/config/manifest')
            ->assertOk()
            ->assertJsonPath('data.schemaVersion', 1)
            ->assertJsonPath('data.blueprints.0.uuid', $blueprintUuid);

        $farmUuid = '1764580414362-7419169';
        $subjectUuid = $this->withHeaders($headers)->postJson('/api/v1/dynamic/subjects', [
            'farmUuid' => $farmUuid,
            'blueprintUuid' => $blueprintUuid,
            'blueprintVersion' => 1,
            'unitType' => 'individual',
            'sexCode' => 'female',
            'displayName' => 'Dynamic subject',
            'payload' => ['display_name' => 'Dynamic subject'],
        ])->assertCreated()->json('data.uuid');
        $this->withHeaders($headers)->getJson("/api/v1/dynamic/subjects?farmUuid=$farmUuid")
            ->assertOk()->assertJsonPath('data.0.uuid', $subjectUuid);
        $this->withHeaders($headers)->getJson("/api/v1/dynamic/tombstones?farmUuid=$farmUuid")->assertOk();

        $idempotencyUuid = (string) Str::uuid();
        $eventPayload = [
            'eventDefinitionUuid' => $eventDefinitionUuid,
            'idempotencyUuid' => $idempotencyUuid,
            'occurredAt' => '2026-10-28T10:20:30.123456Z',
            'baseRevision' => 1,
            'payload' => ['weight' => 45.0],
        ];
        $eventUuid = $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$subjectUuid/events", $eventPayload)
            ->assertCreated()
            ->assertJsonPath('subjectRevision', 2)
            ->json('data.uuid');

        // Replay the exact offline request after a lost acknowledgement.
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$subjectUuid/events", $eventPayload)
            ->assertCreated()
            ->assertJsonPath('data.uuid', $eventUuid);

        $differentTime = $eventPayload;
        $differentTime['occurredAt'] = '2026-10-28T10:20:31.123456Z';
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$subjectUuid/events", $differentTime)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotencyUuid');

        $changedPayload = $eventPayload;
        $changedPayload['payload']['weight'] = 99;
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$subjectUuid/events", $changedPayload)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotencyUuid');

        $this->assertSame(1, EventRecord::count());
        $subject = AnimalSubject::query()->where('uuid', $subjectUuid)->firstOrFail();
        $this->assertSame('45', (string) $subject->attributes_json['current_weight']);
        $this->assertSame(2, $subject->revision);
    }

    public function test_existing_catalog_binding_and_profile_updates_keep_identity_and_retry_safely(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SYSTEM_USER, 'platform_role' => 'superAdmin']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('existing-ui')->plainTextToken];
        app(DynamicV1Installer::class)->install($admin->uuid);
        $blueprint = AnimalBlueprint::query()->firstOrFail();
        $typeId = DB::table('livestock_types')->insertGetId(['name' => 'Existing catalog type']);
        $speciesId = DB::table('species')->insertGetId(['name' => 'Existing catalog species', 'livestockTypeId' => $typeId]);
        $breedId = DB::table('breeds')->insertGetId(['name' => 'Existing breed', 'group' => 'existing', 'livestockTypeId' => $typeId]);
        $blueprint->forceFill(['settings_json' => array_replace($blueprint->settings_json, [
            'catalogBinding' => ['livestockTypeId' => $typeId, 'speciesId' => $speciesId],
        ])])->save();
        app(ConfigurationSyncService::class)->publishRevision($admin->uuid);
        $this->withHeaders($headers)->getJson('/api/v1/config/manifest')->assertOk()
            ->assertJsonPath('data.catalogBindings.0.speciesId', $speciesId)
            ->assertJsonPath('data.catalogBindings.0.blueprintUuid', $blueprint->uuid);
        $uuid = (string) Str::uuid();
        $context = ['livestockTypeId' => $typeId, 'speciesId' => $speciesId, 'breedId' => $breedId,
            'name' => 'Original name', 'gender' => 'female', 'identificationNumber' => 'VISUAL-001'];
        $registration = ['uuid' => $uuid, 'farmUuid' => (string) Str::uuid(),
            'blueprintUuid' => $blueprint->uuid, 'blueprintVersion' => $blueprint->version,
            'unitType' => 'individual', 'sexCode' => 'female', 'displayName' => 'Original name',
            'registrationContext' => $context, 'payload' => ['display_name' => 'Original name', 'notes' => 'Authored field']];
        $this->withHeaders($headers)->postJson('/api/v1/dynamic/subjects', $registration)->assertCreated()
            ->assertJsonPath('data.uuid', $uuid)->assertJsonPath('data.registration_context_json.breedId', $breedId);
        $update = ['uuid' => $uuid, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 1,
            'registrationContext' => array_replace($context, ['name' => 'Updated name', 'gender' => 'male']),
            'payload' => ['display_name' => 'Updated name', 'notes' => 'Updated authored field']];
        $response = $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $update)
            ->assertOk()->assertJsonPath('data.uuid', $uuid)->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.sex_code', 'male')->json('data');
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $update)
            ->assertOk()->assertJsonPath('data', $response);
        $this->assertDatabaseCount('animal_subjects', 1);
        $this->assertDatabaseCount('dynamic_mutation_receipts', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $changed = $update;
        $changed['payload']['notes'] = 'Different command';
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $changed)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotencyUuid');
        $changed['idempotencyUuid'] = (string) Str::uuid();
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $changed)
            ->assertUnprocessable()->assertJsonValidationErrors('baseRevision');
        $changed['baseRevision'] = 2;
        $changed['registrationContext']['speciesId'] = 999999;
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $changed)->assertUnprocessable();
        $other = User::factory()->create(['role' => UserRole::FARMER]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$other->createToken('other')->plainTextToken])
            ->postJson("/api/v1/dynamic/subjects/$uuid", $update)->assertForbidden();
    }

    public function test_group_sync_keeps_quantity_and_the_paired_identifier(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SYSTEM_USER, 'platform_role' => 'superAdmin']);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('group-ui')->plainTextToken];
        app(DynamicV1Installer::class)->install($actor->uuid);
        $blueprint = AnimalBlueprint::query()->firstOrFail();
        $uuid = (string) Str::uuid();
        $farm = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'farmUuid' => $farm, 'blueprintUuid' => $blueprint->uuid,
            'blueprintVersion' => $blueprint->version, 'unitType' => 'group', 'sexCode' => 'not_applicable',
            'quantity' => 25, 'quantityUnit' => 'animals', 'pairedTag' => 'flock-001', 'payload' => ['notes' => 'Population']];
        $this->withHeaders($headers)->postJson('/api/v1/dynamic/subjects', $body)->assertCreated()
            ->assertJsonPath('data.quantity', 25)->assertJsonPath('data.identifiers.0.normalized_value', 'FLOCK-001')
            ->assertJsonPath('data.identifiers.0.type.code', 'paired_rfid_barcode');
        $update = ['uuid' => $uuid, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 1,
            'quantity' => 30, 'pairedTag' => 'flock-002', 'payload' => ['notes' => 'Recounted']];
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $update)->assertOk()
            ->assertJsonPath('data.quantity', 30)->assertJsonPath('data.identifiers.0.normalized_value', 'FLOCK-002');
        $this->withHeaders($headers)->postJson("/api/v1/dynamic/subjects/$uuid", $update)->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('subject_identifiers', 2);
        $this->assertDatabaseHas('subject_identifiers', ['normalized_value' => 'FLOCK-001', 'status' => 'retired']);
        $this->withHeaders($headers)->getJson('/api/v1/dynamic/subjects?farmUuid='.$farm)->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.identifiers.0.normalized_value', 'FLOCK-002');
    }

    public function test_form_schema_validation_rejects_reserved_fields(): void
    {
        try {
            app(FormSchemaValidator::class)->assertDefinition(['sections' => [['key' => 'main', 'fields' => [
                $this->field('uuid', 'text', 'string', true),
            ]]]]);
            $this->fail('A reserved field key was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schema', $exception->errors());
        }
    }

    private function field(string $key, string $component, string $valueType, bool $required, array $validation = []): array
    {
        return [
            'field_uuid' => (string) Str::uuid(),
            'key' => $key,
            'label' => ['en' => str($key)->replace('_', ' ')->title()->toString()],
            'component' => $component,
            'value_type' => $valueType,
            'data_owner' => 'user_input',
            'required' => $required ? 'always' : 'optional',
            'nullable' => ! $required,
            'validation' => $validation,
        ];
    }
}
