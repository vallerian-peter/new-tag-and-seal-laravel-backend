<?php

namespace Tests\Feature\Dynamic;

use App\Enums\UserRole;
use App\Models\AnimalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PublishesConfiguration;
use Tests\TestCase;

/** Phase 1: behaviour contract, summaries, change feed, voiding, reports and photos. */
class BehaviorPlatformTest extends TestCase
{
    use PublishesConfiguration;
    use RefreshDatabase;

    private array $headers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::SYSTEM_USER]);
        foreach (DB::table('platform_permissions')->pluck('id') as $id) {
            DB::table('user_platform_permissions')->insert(['user_uuid' => $this->admin->uuid, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('test')->plainTextToken];
    }

    public function test_catalogue_and_manifest_describe_behaviours_families_and_capabilities(): void
    {
        $this->assertSame(22, DB::table('behavior_definitions')->count());
        $setup = $this->publishPig();
        $manifest = $this->withHeaders($this->headers)->getJson('/api/v1/config/manifest')->assertOk()->json('data');

        $this->assertSame(1, $manifest['schemaVersion']);
        $birth = collect($manifest['behaviors'])->firstWhere('code', 'record_birth');
        $this->assertSame('births', $birth['family']);
        $this->assertSame(['born_alive', 'born_dead', 'birth_type', 'offspring'], array_column($birth['roles'], 'role'));
        $this->assertSame('legacy', $manifest['families']['births']);
        $this->assertSame('live_birth', collect($manifest['species'])->firstWhere('uuid', $setup['species'])['capabilities']['reproduction']);
        $this->assertSame('births', collect($manifest['events'])->firstWhere('uuid', $setup['events']['farrowing'])['family']);
    }

    public function test_behaviour_check_rejects_events_that_do_not_satisfy_their_behaviour(): void
    {
        $species = $this->species('hen_test', 'egg_laying');
        $registration = $this->form('hen.reg', 'registration', [$this->field('name', 'text', 'string', false)]);
        $badBirth = $this->form('hen.birth', 'event', [
            $this->field('alive', 'decimal', 'decimal', true, ['role' => 'born_alive']),
            $this->field('colour', 'text', 'string', false, ['role' => 'feather_colour']),
        ]);
        $blueprint = $this->blueprint($species, 'hen.standard', $registration);
        $errors = collect($this->behaviourErrors($this->event($blueprint, 'bad_birth', $badBirth, 'record_birth')))
            ->map(fn ($error) => "{$error['path']}: {$error['code']}")->all();
        $this->assertContains('capabilities.reproduction: BEHAVIOR_CAPABILITY_MISSING', $errors);
        $this->assertContains('roles.born_alive: BEHAVIOR_ROLE_COMPONENT_MISMATCH', $errors);
        $this->assertContains('roles.feather_colour: BEHAVIOR_ROLE_UNKNOWN', $errors);
    }

    public function test_birth_with_offspring_updates_reproduction_and_creates_linked_animals(): void
    {
        $setup = $this->publishPig();
        $sow = $this->register($setup, 'individual', ['sexCode' => 'female', 'displayName' => 'Sow 1']);

        $event = $this->submit($sow, $setup['events']['farrowing'], 1, [
            'piglets_alive' => 2,
            'piglets_dead' => 1,
            'litter' => [['sex_code' => 'female', 'weight' => 1.4], ['sex_code' => 'male']],
        ], '2026-10-20T08:00:00Z')->assertCreated()->json('data');

        $this->assertSame('record_birth', $event['behavior_code']);
        $state = DB::table('subject_reproduction_state')->where('subject_uuid', $sow)->first();
        $this->assertSame([1, 2, 1], [(int) $state->births_count, (int) $state->total_born_alive, (int) $state->last_born_dead]);
        $piglets = AnimalSubject::query()->where('attributes_json->mother_uuid', $sow)->get();
        $this->assertCount(2, $piglets);
        $this->assertSame($event['uuid'], $piglets[0]->attributes_json['birth_event_uuid']);
        $this->assertSame('2026-10-20', $piglets[0]->birth_or_start_date->toDateString());
        $this->assertSame(1.4, $piglets[0]->attributes_json['birth_weight']);

        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/{$event['uuid']}/void", ['reason' => 'Entered twice'])
            ->assertUnprocessable()->assertJsonPath('errors.event.0', 'EVENT_NOT_REVERSIBLE');

        $this->submit($sow, $setup['events']['farrowing'], 2, ['piglets_alive' => 3, 'litter' => [['sex_code' => 'female'], ['sex_code' => 'female'], ['sex_code' => 'male'], ['sex_code' => 'male']]])
            ->assertUnprocessable()->assertJsonValidationErrors('offspring');
    }

    public function test_group_birth_mortality_and_void_keep_the_head_count_correct(): void
    {
        $setup = $this->publishPig();
        $pen = $this->register($setup, 'group', ['quantity' => 10]);

        $this->submit($pen, $setup['events']['farrowing'], 1, ['piglets_alive' => 4])->assertCreated();
        $this->assertSame(14, AnimalSubject::where('uuid', $pen)->value('quantity'));

        $loss = $this->submit($pen, $setup['events']['mortality'], 2, ['lost' => 3, 'cause' => 'heat'])->assertCreated()->json('data.uuid');
        $this->assertSame(11, AnimalSubject::where('uuid', $pen)->value('quantity'));
        $this->submit($pen, $setup['events']['mortality'], 3, ['lost' => 50])->assertUnprocessable()->assertJsonValidationErrors('count');

        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$loss/void", ['reason' => 'Animals were found'])->assertOk()
            ->assertJsonPath('data.record_status', 'voided');
        $subject = AnimalSubject::where('uuid', $pen)->first();
        $this->assertSame(14, $subject->quantity);
        $this->assertSame(4, $subject->revision);
        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$loss/void", ['reason' => 'Retry'])->assertOk();
        $this->assertSame(14, AnimalSubject::where('uuid', $pen)->value('quantity'));
    }

    public function test_weight_void_restores_the_previous_weight_and_stage_change_cannot_be_voided(): void
    {
        $setup = $this->publishPig();
        $boar = $this->register($setup, 'individual', ['sexCode' => 'male']);
        $this->submit($boar, $setup['events']['weight'], 1, ['weight' => 80.5], '2026-10-01T08:00:00Z')->assertCreated();
        $second = $this->submit($boar, $setup['events']['weight'], 2, ['weight' => 92], '2026-10-15T08:00:00Z')->assertCreated()->json('data.uuid');
        $this->assertEquals(92, AnimalSubject::where('uuid', $boar)->first()->attributes_json['current_weight']);

        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$second/void", ['reason' => 'Scale error'])->assertOk();
        $this->assertEquals(80.5, AnimalSubject::where('uuid', $boar)->first()->attributes_json['current_weight']);

        $stage = $this->submit($boar, $setup['events']['stage'], 4, ['stage_code' => 'grower'])->assertCreated()->json('data.uuid');
        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$stage/void", ['reason' => 'Mistake'])
            ->assertUnprocessable()->assertJsonPath('errors.event.0', 'EVENT_NOT_REVERSIBLE');
    }

    public function test_egg_production_uses_legacy_key_names_and_rebuild_detects_and_repairs_drift(): void
    {
        $species = $this->species('hen_test', 'egg_laying');
        $registration = $this->form('hen.reg', 'registration', [$this->field('name', 'text', 'string', false)]);
        $eggs = $this->form('hen.eggs', 'event', [
            $this->field('total_eggs', 'integer', 'integer', true),
            $this->field('broken_eggs', 'integer', 'integer', false),
        ]);
        $blueprint = $this->blueprint($species, 'hen.standard', $registration);
        $eggEvent = $this->event($blueprint, 'egg_collection', $eggs, 'record_egg_or_clutch');
        $this->publishBlueprint($blueprint);
        $flock = $this->register(['blueprint' => $blueprint, 'farm' => $this->farm()], 'group', ['quantity' => 40]);

        $this->submit($flock, $eggEvent, 1, ['total_eggs' => 30, 'broken_eggs' => 2], '2026-10-27T07:00:00Z')->assertCreated();
        $evening = $this->submit($flock, $eggEvent, 2, ['total_eggs' => 12], '2026-10-27T17:00:00Z')->assertCreated()->json('data.uuid');
        $row = DB::table('subject_production_daily')->where('subject_uuid', $flock)->sole();
        $this->assertSame(['eggs', 42.0, 2.0, 2], [$row->product_code, (float) $row->quantity, (float) $row->damaged_quantity, (int) $row->event_count]);

        $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/events/$evening/void", ['reason' => 'Counted twice'])->assertOk();
        $this->assertSame(30.0, (float) DB::table('subject_production_daily')->where('subject_uuid', $flock)->value('quantity'));

        $this->artisan('tag-and-seal:projections', ['--check' => true])->assertSuccessful();
        DB::table('subject_production_daily')->update(['quantity' => 999]);
        DB::table('subject_reproduction_state')->insert(['subject_uuid' => 'ghost', 'farm_uuid' => 'f', 'births_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('tag-and-seal:projections', ['--check' => true])->assertFailed();
        $this->artisan('tag-and-seal:projections')->assertSuccessful();
        $this->artisan('tag-and-seal:projections', ['--check' => true])->assertSuccessful();
        $this->assertSame(30.0, (float) DB::table('subject_production_daily')->where('subject_uuid', $flock)->value('quantity'));
        $this->assertSame(0, DB::table('subject_reproduction_state')->where('subject_uuid', 'ghost')->count());
    }

    public function test_change_feed_pages_in_order_and_only_shows_the_users_farms(): void
    {
        $setup = $this->publishPig();
        $farmer = User::factory()->create(['role' => UserRole::FARMER, 'roleId' => 501]);
        $own = $this->farm(501);
        $other = $this->farm(777);
        $mine = $this->register(['farm' => $own] + $setup, 'individual', ['sexCode' => 'female']);
        $this->register(['farm' => $other] + $setup, 'individual', ['sexCode' => 'female']);
        $this->submit($mine, $setup['events']['weight'], 1, ['weight' => 50])->assertCreated();

        $first = $this->actingWithToken($farmer)->getJson('/api/v1/sync/changes?limit=2')->assertOk();
        $first->assertJsonPath('hasMore', true)->assertJsonCount(2, 'data');
        $this->assertSame([$own], array_unique(array_column($first->json('data'), 'farmUuid')));
        $second = $this->actingWithToken($farmer)->getJson('/api/v1/sync/changes?limit=2&cursor='.$first->json('nextCursor'))->assertOk();
        $all = [...$first->json('data'), ...$second->json('data')];
        $this->assertFalse($second->json('hasMore'));
        $this->assertSame(array_column($all, 'cursor'), collect($all)->pluck('cursor')->sort()->values()->all());
        $latestSubject = collect($all)->where('entityType', 'animal_subject')->last();
        $this->assertSame(2, $latestSubject['revision']);
        $this->assertEquals(50, $latestSubject['data']['attributes_json']['current_weight']);
        $this->assertSame('event_record', collect($all)->last()['entityType'] === 'event_record' ? 'event_record' : collect($all)->firstWhere('entityType', 'event_record')['entityType']);

        $this->actingWithToken($farmer)->getJson("/api/v1/sync/changes?farmUuid=$other")->assertForbidden();
        $this->actingWithToken($farmer)->getJson('/api/v1/sync/changes?cursor='.$second->json('nextCursor'))->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('nextCursor', $second->json('nextCursor'));
    }

    public function test_mobile_void_is_limited_to_the_author_or_permitted_users(): void
    {
        $setup = $this->publishPig();
        $farm = $this->farm(601);
        $owner = User::factory()->create(['role' => UserRole::FARMER, 'roleId' => 601]);
        $stranger = User::factory()->create(['role' => UserRole::FARMER, 'roleId' => 602]);
        $sow = $this->register(['farm' => $farm] + $setup, 'individual', ['sexCode' => 'female']);
        $event = $this->submit($sow, $setup['events']['weight'], 1, ['weight' => 70])->assertCreated()->json('data.uuid');

        $this->actingWithToken($stranger)->postJson("/api/v1/dynamic/events/$event/void", ['reason' => 'Not mine'])->assertForbidden();
        $this->actingWithToken($owner)->postJson("/api/v1/dynamic/events/$event/void", ['reason' => 'Not mine'])->assertForbidden();
        $this->actingWithToken($this->admin)->postJson("/api/v1/dynamic/events/$event/void", ['reason' => 'Correction'])->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'dynamic_event.voided')->count());

        $revision = AnimalSubject::where('uuid', $sow)->value('revision');
        $own = $this->actingWithToken($owner)->postJson("/api/v1/dynamic/subjects/$sow/events", [
            'eventDefinitionUuid' => $setup['events']['weight'], 'idempotencyUuid' => (string) Str::uuid(),
            'baseRevision' => $revision, 'payload' => ['weight' => 71],
        ])->assertCreated()->json('data.uuid');
        $this->actingWithToken($owner)->postJson("/api/v1/dynamic/events/$own/void", ['reason' => 'My typo'])->assertOk();
    }

    public function test_reports_read_summaries_and_respect_farm_scope(): void
    {
        $setup = $this->publishPig();
        $sow = $this->register($setup, 'individual', ['sexCode' => 'female']);
        $pen = $this->register($setup, 'group', ['quantity' => 5]);
        $this->submit($sow, $setup['events']['farrowing'], 1, ['piglets_alive' => 9, 'piglets_dead' => 1], '2026-10-10T08:00:00Z')->assertCreated();
        $this->submit($pen, $setup['events']['mortality'], 1, ['lost' => 2])->assertCreated();

        $reproduction = $this->withHeaders($this->headers)->getJson("/api/v1/reports/reproduction?farmUuid={$setup['farm']}")->assertOk()->json('data');
        $this->assertSame(['mothers' => 1, 'births' => 1, 'bornAlive' => 9, 'bornDead' => 1], $reproduction['totals']);
        $animals = collect($this->withHeaders($this->headers)->getJson('/api/v1/reports/animals')->assertOk()->json('data'));
        $this->assertSame(4, (int) $animals->sum('head_count'));
        $events = collect($this->withHeaders($this->headers)->getJson('/api/v1/reports/events?from=2026-01-01&to=2030-01-01')->assertOk()->json('data'));
        $this->assertSame('births', $events->firstWhere('event_code', 'farrowing')['family']);
        $this->withHeaders($this->headers)->getJson('/api/v1/reports/production?from=2026-10-10&to=2026-10-01')->assertUnprocessable();

        $farmer = User::factory()->create(['role' => UserRole::FARMER, 'roleId' => 901]);
        $this->actingWithToken($farmer)->getJson('/api/v1/reports/reproduction')->assertOk()
            ->assertJsonPath('data.totals.births', 0);
    }

    public function test_photos_upload_once_and_are_required_to_exist_when_submitted(): void
    {
        Storage::fake('local');
        $species = $this->species('pig_photo', 'live_birth');
        $registration = $this->form('pigp.reg', 'registration', [$this->field('name', 'text', 'string', false)]);
        $check = $this->form('pigp.check', 'event', [$this->field('proof', 'photo', 'file', true)]);
        $blueprint = $this->blueprint($species, 'pigp.standard', $registration);
        $event = $this->event($blueprint, 'photo_check', $check, 'record_only');
        $this->publishBlueprint($blueprint);
        $farm = $this->farm();
        $sow = $this->register(['blueprint' => $blueprint, 'farm' => $farm], 'individual', ['sexCode' => 'female']);

        $uuid = (string) Str::uuid();
        $upload = fn () => $this->withHeaders($this->headers)->post('/api/v1/dynamic/files', [
            'uuid' => $uuid, 'farmUuid' => $farm, 'file' => UploadedFile::fake()->image('proof.jpg'),
        ], ['Accept' => 'application/json']);
        $upload()->assertCreated()->assertJsonPath('data.uuid', $uuid);
        $upload()->assertCreated();
        $this->assertSame(1, DB::table('dynamic_files')->count());
        $this->withHeaders($this->headers)->get("/api/v1/dynamic/files/$uuid")->assertOk();

        $this->submit($sow, $event, 1, ['proof' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('errors.proof.0', 'FILE_NOT_FOUND');
        $this->submit($sow, $event, 1, ['proof' => $uuid])->assertCreated();
    }

    /** Auth guards cache the first user of a test; reset them when switching users. */
    private function actingWithToken(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    /** Pig species with farrowing (record_birth), mortality, weight and stage events. */
    private function publishPig(): array
    {
        $species = $this->species('pig_test', 'live_birth');
        $registration = $this->form('pig.reg', 'registration', [$this->field('name', 'text', 'string', false)]);
        $farrowing = $this->form('pig.farrowing', 'event', [
            $this->field('piglets_alive', 'integer', 'integer', true, ['role' => 'born_alive']),
            $this->field('piglets_dead', 'integer', 'integer', false, ['role' => 'born_dead']),
            $this->field('litter', 'repeatable_group', 'array', false, ['role' => 'offspring', 'item_fields' => [
                ['key' => 'sex_code', 'label' => ['en' => 'Sex'], 'component' => 'single_select', 'required' => 'always', 'validation' => ['allowed_values' => ['male', 'female']]],
                ['key' => 'weight', 'label' => ['en' => 'Weight'], 'component' => 'measurement', 'unit' => 'kg'],
            ]]),
        ]);
        $mortality = $this->form('pig.mortality', 'event', [
            $this->field('lost', 'integer', 'integer', true, ['role' => 'count']),
            $this->field('cause', 'text', 'string', false),
        ]);
        $weight = $this->form('pig.weight', 'event', [$this->field('weight', 'decimal', 'decimal', true)]);
        $stage = $this->form('pig.stage', 'event', [$this->field('stage_code', 'text', 'string', true)]);
        $blueprint = $this->blueprint($species, 'pig.standard', $registration);
        $events = [
            'farrowing' => $this->event($blueprint, 'farrowing', $farrowing, 'record_birth'),
            'mortality' => $this->event($blueprint, 'mortality', $mortality, 'record_mortality'),
            'weight' => $this->event($blueprint, 'weight', $weight, 'update_weight'),
            'stage' => $this->event($blueprint, 'stage', $stage, 'transition_stage'),
        ];
        $this->publishBlueprint($blueprint);

        return ['species' => $species, 'blueprint' => $blueprint, 'events' => $events, 'farm' => $this->farm()];
    }

    private function species(string $code, string $reproduction): string
    {
        return $this->draftSpecies($code, ['subjectModes' => ['individual', 'group'], 'reproduction' => $reproduction, 'products' => []]);
    }

    private function form(string $code, string $purpose, array $fields): string
    {
        return $this->publishedForm($code, $purpose, $fields);
    }

    private function blueprint(string $species, string $code, string $registration): string
    {
        return $this->draftBlueprint($species, $code, $registration);
    }

    private function event(string $blueprint, string $code, string $form, string $behavior): string
    {
        return $this->draftEvent($blueprint, $code, $form, $behavior);
    }

    private function field(string $key, string $component, string $valueType, bool $required, array $extra = []): array
    {
        return [
            'field_uuid' => (string) Str::uuid(), 'key' => $key, 'label' => ['en' => $key],
            'component' => $component, 'value_type' => $valueType, 'data_owner' => 'user_input',
            'required' => $required ? 'always' : 'optional', 'nullable' => ! $required,
        ] + $extra;
    }

    private function farm(int $farmerId = 1): string
    {
        $uuid = (string) Str::uuid();
        // The test transaction never commits, so deferred foreign keys are never checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('farms')->insert([
            'farmerId' => $farmerId, 'uuid' => $uuid, 'referenceNo' => Str::random(10), 'name' => 'Farm',
            'size' => 1, 'physicalAddress' => 'Test', 'countryId' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function register(array $setup, string $unitType, array $extra): string
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/dynamic/subjects', [
            'farmUuid' => $setup['farm'], 'blueprintUuid' => $setup['blueprint'], 'blueprintVersion' => 1,
            'unitType' => $unitType, 'payload' => ['name' => 'Animal'],
        ] + $extra)->assertCreated()->json('data.uuid');
    }

    private function submit(string $subject, string $event, int $baseRevision, array $payload, ?string $occurredAt = null)
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/dynamic/subjects/$subject/events", array_filter([
            'eventDefinitionUuid' => $event, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => $baseRevision,
            'occurredAt' => $occurredAt, 'payload' => $payload,
        ], fn ($value) => $value !== null));
    }
}
