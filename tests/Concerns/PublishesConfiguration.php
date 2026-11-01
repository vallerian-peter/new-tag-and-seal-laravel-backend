<?php

namespace Tests\Concerns;

use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Services\Dynamic\BehaviorCatalog;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\FormSchemaValidator;

/**
 * Writes configuration the way the Portal publishes it into the shared database.
 * The Portal owns authoring and publication; the backend validates and serves it.
 */
trait PublishesConfiguration
{
    protected function draftSpecies(string $code, ?array $capabilities = null, string $mode = 'both', array $attributes = []): string
    {
        return AnimalSpecies::create([
            'code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code)), 'default_subject_mode' => $mode,
            'capabilities_json' => $capabilities, 'status' => 'draft',
        ] + $attributes)->uuid;
    }

    protected function publishedForm(string $code, string $purpose, array $fields, int $version = 1): string
    {
        $schema = ['sections' => [['key' => 'main', 'label' => ['en' => 'Main'], 'fields' => $fields]]];
        app(FormSchemaValidator::class)->assertDefinition($schema);

        return FormDefinition::create([
            'code' => $code, 'version' => $version, 'purpose' => $purpose, 'status' => 'published',
            'system_envelope_version' => 1, 'schema_json' => $schema, 'minimum_app_schema_version' => 1,
            'checksum' => 'sha256:'.hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)), 'published_at' => now(),
        ])->uuid;
    }

    protected function draftBlueprint(string $species, string $code, string $registrationForm, array $settings = ['subjectModes' => ['individual', 'group']]): string
    {
        return AnimalBlueprint::create([
            'species_uuid' => $species, 'code' => $code, 'version' => 1, 'status' => 'draft',
            'registration_form_uuid' => $registrationForm, 'registration_form_version' => 1, 'settings_json' => $settings,
        ])->uuid;
    }

    protected function draftEvent(string $blueprint, string $code, string $form, string $behavior, array $attributes = []): string
    {
        return EventDefinition::create([
            'blueprint_uuid' => $blueprint, 'blueprint_version' => 1, 'code' => $code, 'name' => ucfirst($code),
            'form_uuid' => $form, 'form_version' => 1, 'behavior_code' => $behavior, 'status' => 'draft',
        ] + $attributes)->uuid;
    }

    /** @return array<int, array{code: string, path: string}> what the Portal's publish check reports for one event */
    protected function behaviourErrors(string $event): array
    {
        $event = EventDefinition::where('uuid', $event)->sole();
        $blueprint = AnimalBlueprint::where('uuid', $event->blueprint_uuid)->sole();
        $schema = FormDefinition::where('uuid', $event->form_uuid)->where('version', $event->form_version)->value('schema_json');

        return app(BehaviorCatalog::class)->validateEvent($event->behavior_code, $schema, $blueprint->settings_json['subjectModes'],
            AnimalSpecies::where('uuid', $blueprint->species_uuid)->value('capabilities_json'));
    }

    protected function publishBlueprint(string $uuid): void
    {
        $blueprint = AnimalBlueprint::where('uuid', $uuid)->sole();
        $events = EventDefinition::where('blueprint_uuid', $uuid)->where('blueprint_version', $blueprint->version)->get();
        foreach ($events as $event) {
            $this->assertSame([], $this->behaviourErrors($event->uuid), "Event {$event->code} breaks its behaviour contract.");
        }
        $blueprint->forceFill(['status' => 'published', 'published_at' => now(),
            'compiled_manifest_json' => ['subjectModes' => $blueprint->settings_json['subjectModes'], 'registrationForm' => $blueprint->registration_form_uuid.'@1']])->save();
        AnimalSpecies::where('uuid', $blueprint->species_uuid)->update(['status' => 'published', 'published_at' => now()]);
        EventDefinition::whereIn('id', $events->pluck('id'))->update(['status' => 'published']);
        app(ConfigurationSyncService::class)->publishRevision();
    }
}
