<?php

namespace Tests\Feature\Dynamic;

use App\Enums\UserRole;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\ConfigurationRevision;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Models\LookupSet;
use App\Models\SyncTombstone;
use App\Models\User;
use App\Services\Dynamic\ConfigurationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalManifestContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_compilation_keeps_the_default_english_label(): void
    {
        $lookup = LookupSet::create(['code' => 'check_kind', 'name' => 'Check kind', 'version' => 1, 'status' => 'published']);
        $lookup->options()->create(['code' => 'routine', 'label' => 'Routine check', 'translations_json' => ['en' => '', 'sw' => 'Ukaguzi wa kawaida']]);
        $manifest = app(ConfigurationSyncService::class)->compile(0);
        $this->assertSame(['en' => 'Routine check', 'sw' => 'Ukaguzi wa kawaida'], $manifest['lookups'][0]['options'][0]['label']);
    }

    public function test_portal_requests_are_compiled_once_by_the_backend_before_the_manifest_is_served(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SYSTEM_USER]);
        $before = (int) ConfigurationRevision::max('revision');
        DB::table('platform_feature_flags')->updateOrInsert(['key' => 'family.health.authority'], ['enabled' => true, 'value_json' => json_encode(['authority' => 'ledger_shadow']), 'updated_at' => now(), 'created_at' => now()]);
        foreach (['family_authority.changed', 'lookup_set.published'] as $reason) {
            DB::table('configuration_publish_requests')->insert(['requested_by_uuid' => $actor->uuid, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->withToken($actor->createToken('manifest')->plainTextToken);
        $manifest = $this->getJson('/api/v1/config/manifest')->assertOk()->json('data');
        $this->assertSame($before + 1, $manifest['revision']);
        $this->assertSame('ledger_shadow', $manifest['families']['health']);
        $this->assertSame($actor->uuid, ConfigurationRevision::where('revision', $before + 1)->value('published_by_uuid'));
        $this->assertSame(2, DB::table('configuration_publish_requests')->where('revision', $before + 1)->count());

        $this->getJson('/api/v1/config/manifest')->assertOk()->assertJsonPath('data.revision', $before + 1);
        $this->artisan('tag-and-seal:publish-configuration')->expectsOutput('No configuration changes waiting.')->assertSuccessful();
    }

    public function test_portal_contract_registers_records_events_and_hides_archived_animals(): void
    {
        $path = getenv('DYNAMIC_PORTAL_MANIFEST_FIXTURE') ?: base_path('../new-tag-and-seal-flutter-app/test/fixtures/portal_manifest.json');
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        // Reconstruct the shared DB rows represented by actual Portal output.
        foreach ($manifest['species'] as $species) {
            AnimalSpecies::create($species + ['status' => 'published']);
        }
        foreach ($manifest['forms'] as $form) {
            FormDefinition::create([
                'uuid' => $form['uuid'], 'code' => $form['code'], 'version' => $form['version'],
                'purpose' => $form['purpose'], 'schema_json' => $form['schema'],
                'checksum' => $form['checksum'], 'status' => 'published',
            ]);
        }
        foreach ($manifest['blueprints'] as $blueprint) {
            [$formUuid, $version] = explode('@', $blueprint['registrationForm']);
            AnimalBlueprint::create([
                'uuid' => $blueprint['uuid'], 'species_uuid' => $blueprint['speciesUuid'], 'code' => $blueprint['code'],
                'version' => $blueprint['version'], 'registration_form_uuid' => $formUuid, 'registration_form_version' => $version,
                'settings_json' => $blueprint['settings'], 'status' => 'published',
            ]);
        }
        foreach ($manifest['events'] as $event) {
            [$formUuid, $version] = explode('@', $event['form']);
            EventDefinition::create([
                'uuid' => $event['uuid'], 'blueprint_uuid' => $event['blueprintUuid'], 'blueprint_version' => $event['blueprintVersion'],
                'code' => $event['code'], 'name' => $event['name'], 'form_uuid' => $formUuid, 'form_version' => $version,
                'behavior_code' => $event['behavior'], 'status' => 'published',
            ]);
        }
        ConfigurationRevision::create(['revision' => $manifest['revision'], 'schema_version' => '1', 'checksum' => $manifest['checksum'], 'manifest_json' => $manifest, 'published_at' => now()]);
        $actor = User::factory()->create(['role' => UserRole::SYSTEM_USER]);
        $this->withToken($actor->createToken('contract')->plainTextToken);
        $wireManifest = $this->getJson('/api/v1/config/manifest')->assertOk()->json('data');
        $this->assertSame($manifest, $wireManifest);
        if ($output = getenv('DYNAMIC_BACKEND_MANIFEST_FIXTURE')) {
            file_put_contents($output, json_encode($wireManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $farm = (string) Str::uuid();
        $blueprint = $manifest['blueprints'][0];
        $subject = $this->postJson('/api/v1/dynamic/subjects', [
            'farmUuid' => $farm, 'blueprintUuid' => $blueprint['uuid'], 'blueprintVersion' => $blueprint['version'],
            'unitType' => 'individual', 'sexCode' => 'female', 'payload' => ['notes' => 'Portal contract subject'],
        ])->assertCreated()->json('data.uuid');
        $request = ['eventDefinitionUuid' => $manifest['events'][0]['uuid'], 'baseRevision' => 1, 'idempotencyUuid' => (string) Str::uuid(), 'payload' => ['notes' => 'Observation']];
        $event = $this->postJson("/api/v1/dynamic/subjects/$subject/events", $request)->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/dynamic/subjects/$subject/events", $request)->assertCreated()->assertJsonPath('data.uuid', $event);
        // What a Portal archive leaves in the shared database (exercised for real in scripts/test_dynamic_flow.py).
        $archived = AnimalSubject::where('uuid', $subject)->sole();
        $archived->delete();
        $tombstone = SyncTombstone::create(['uuid' => (string) Str::uuid(), 'entity_type' => 'animal_subject', 'entity_uuid' => $subject,
            'farm_uuid' => $farm, 'entity_revision' => $archived->revision, 'operation' => 'deleted', 'occurred_at' => now()]);
        $this->getJson("/api/v1/dynamic/subjects?farmUuid=$farm")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/dynamic/subjects/$subject/events")->assertNotFound();
        $this->postJson("/api/v1/dynamic/subjects/$subject/events", ['idempotencyUuid' => (string) Str::uuid()] + $request)->assertNotFound();
        $this->getJson("/api/v1/dynamic/tombstones?farmUuid=$farm")->assertOk()->assertJsonPath('data.0.entity_uuid', $subject);
        $archived->restore();
        $tombstone->update(['superseded_at' => now()]);
        $this->getJson("/api/v1/dynamic/subjects?farmUuid=$farm")->assertOk()->assertJsonPath('data.0.uuid', $subject);
        $this->getJson("/api/v1/dynamic/subjects/$subject/events")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/dynamic/tombstones?farmUuid=$farm")->assertOk()->assertJsonCount(0, 'data');
        $farmer = User::factory()->create(['role' => UserRole::FARMER]);
        $this->withToken($farmer->createToken('restricted-contract')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/dynamic/tombstones?farmUuid=$farm")->assertForbidden();
    }
}
