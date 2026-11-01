<?php

namespace Tests\Feature\Dynamic;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Run via scripts/test_dynamic_flow.py. Uses the Portal's actual database snapshot. */
class PortalSharedDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $path = getenv('DYNAMIC_PORTAL_DATABASE_FIXTURE');
        if (! $path) {
            $this->markTestSkipped('Run the cross-app acceptance command to provide a temporary Portal database.');
        }
        $realPath = realpath($path);
        $this->assertNotFalse($realPath);
        $this->assertStringStartsWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR, $realPath);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $realPath]);
        DB::purge('sqlite');
        $actor = User::where('email', 'portal@example.test')->sole();
        $this->withToken($actor->createToken('temporary-cross-app-test')->plainTextToken);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_api_serves_portal_database_manifest(): void
    {
        $this->assertSame(0, DB::table('configuration_revisions')->count(), 'the Portal queues; only the backend compiles');
        $blueprint = DB::table('animal_blueprints')->where('status', 'published')->sole();
        $event = DB::table('event_definitions')->where('status', 'published')->sole();

        $wire = $this->getJson('/api/v1/config/manifest')->assertOk()->json('data');
        $this->assertSame(1, $wire['revision']);
        $this->assertSame($blueprint->registration_form_uuid.'@'.$blueprint->registration_form_version, $wire['blueprints'][0]['registrationForm']);
        $this->assertSame($event->form_uuid.'@'.$event->form_version, $wire['events'][0]['form']);
        $this->assertSame('Uchunguzi', $wire['events'][0]['translations']['sw']);
        $checksum = $wire['checksum'];
        unset($wire['checksum']);
        $this->assertSame($checksum, 'sha256:'.hash('sha256', json_encode($wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        $wire['checksum'] = $checksum;
        $this->assertSame(0, DB::table('configuration_publish_requests')->whereNull('fulfilled_at')->count());
        $this->getJson('/api/v1/config/manifest')->assertOk()->assertJsonPath('data.revision', 1);
        file_put_contents(getenv('DYNAMIC_BACKEND_MANIFEST_FIXTURE'), json_encode($wire, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function test_api_accepts_flutter_widget_payload_and_voids_it(): void
    {
        $commands = json_decode(file_get_contents(getenv('DYNAMIC_FLUTTER_SUBMISSION_FIXTURE')), true, 512, JSON_THROW_ON_ERROR);
        $registration = $commands['registration'];
        $subject = $this->postJson('/api/v1/dynamic/subjects', $registration)->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/dynamic/subjects', $registration)->assertCreated()->assertJsonPath('data.uuid', $subject);
        $request = $commands['event'];
        $invalidPayload = $request['payload'];
        $invalidPayload['body_weight'] = 51;
        $invalidPayload['vaccinated'] = 'yes';
        $invalidPayload['signs'] = ['rash'];
        $invalidPayload['respiratory_rate'] = 0;
        $invalidPayload['examined_at'] = 'not-a-date';
        $invalidPayload['region'] = 'arusha';
        $invalidPayload['district'] = 'kinondoni';
        $invalidPayload['treatments'] = [['medicine' => 'Vitamin A', 'dose' => 'heavy']];
        $invalid = $request;
        $invalid['uuid'] = (string) \Illuminate\Support\Str::uuid();
        $invalid['idempotencyUuid'] = (string) \Illuminate\Support\Str::uuid();
        $invalid['payload'] = $invalidPayload;
        $rejected = $this->postJson("/api/v1/dynamic/subjects/$subject/events", $invalid)->assertUnprocessable();
        $rejected->assertJsonPath('errors.body_weight.0', 'MAXIMUM')
            ->assertJsonPath('errors.vaccinated.0', 'INVALID_TYPE')
            ->assertJsonPath('errors.signs.0', 'NOT_ALLOWED')
            ->assertJsonPath('errors.respiratory_rate.0', 'MINIMUM')
            ->assertJsonPath('errors.examined_at.0', 'INVALID_TYPE')
            ->assertJsonPath('errors.district.0', 'LOOKUP_PARENT_MISMATCH');
        $this->assertSame(['INVALID_TYPE'], $rejected->json('errors')['treatments.0.dose']);
        $first = $this->postJson("/api/v1/dynamic/subjects/$subject/events", $request)->assertCreated();
        $first->assertJsonPath('data.payload_json.notes', 'Maelezo kutoka Flutter');
        $first->assertJsonPath('data.payload_json.observation_kind', 'routine');
        $first->assertJsonPath('data.payload_json.body_weight', 12.5);
        $first->assertJsonPath('data.payload_json.vaccinated', false);
        $first->assertJsonPath('data.payload_json.signs', ['fever']);
        $first->assertJsonPath('data.payload_json.pasture', ['latitude' => -6.8, 'longitude' => 39.28]);
        $first->assertJsonPath('data.payload_json.respiratory_rate', 32);
        $first->assertJsonPath('data.payload_json.examined_at', '2026-10-29T08:30:00.000Z');
        $first->assertJsonPath('data.payload_json.region', 'dar_es_salaam');
        $first->assertJsonPath('data.payload_json.district', 'kinondoni');
        $first->assertJsonPath('data.payload_json.treatments.0', ['medicine' => 'Vitamin A', 'dose' => 2.5]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $first->json('data.payload_json.checked_on'));
        $event = $first->json('data.uuid');
        $this->postJson("/api/v1/dynamic/subjects/$subject/events", $request)->assertCreated()->assertJsonPath('data.uuid', $event);
        $this->assertDatabaseCount('event_records', 1);
        $this->postJson("/api/v1/dynamic/events/$event/void", ['reason' => 'Recorded on the wrong animal'])
            ->assertOk()->assertJsonPath('data.record_status', 'voided');
    }

    /** Runs after the Portal archived and restored the animal in the same database. */
    public function test_change_feed_carries_the_portal_archive_and_restore_to_devices(): void
    {
        $subject = DB::table('animal_subjects')->sole();
        $event = DB::table('event_records')->sole();
        $farm = $subject->farm_uuid;
        $this->assertSame(2, DB::table('sync_change_requests')->whereNull('recorded_at')->count(), 'queued by the Portal');

        $feed = $this->getJson("/api/v1/sync/changes?farmUuid=$farm&limit=500")->assertOk()->assertJsonPath('hasMore', false);
        $changes = collect($feed->json('data'));
        $portal = $changes->where('entityUuid', $subject->uuid)->take(-2)->values();
        $this->assertSame(['delete', 'upsert'], $portal->pluck('action')->all());
        $this->assertSame((int) $subject->revision, $portal[1]['revision']);
        $this->assertLessThan($portal[1]['revision'], $portal[0]['revision']);
        $this->assertSame('voided', $changes->where('entityUuid', $event->uuid)->last()['data']['record_status']);
        $this->assertSame($changes->last()['cursor'], $feed->json('nextCursor'));
        $this->assertSame(0, DB::table('sync_change_requests')->whereNull('recorded_at')->count());

        $this->getJson("/api/v1/dynamic/subjects?farmUuid=$farm")->assertOk()->assertJsonPath('data.0.uuid', $subject->uuid);
        $this->getJson("/api/v1/dynamic/tombstones?farmUuid=$farm")->assertOk()->assertJsonCount(0, 'data');
        if ($fixture = getenv('DYNAMIC_BACKEND_CHANGES_FIXTURE')) {
            file_put_contents($fixture, json_encode([
                'farmUuid' => $farm,
                'subjectUuid' => $subject->uuid,
                'eventUuid' => $event->uuid,
                'page' => $feed->json(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
    }
}
