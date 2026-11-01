<?php

namespace App\Services\Dynamic;

use App\Enums\DefinitionStatus;
use App\Enums\EventBehavior;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Models\IdentifierType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlueprintPublisher
{
    public function __construct(
        private readonly FormSchemaValidator $forms,
        private readonly ConfigurationSyncService $configuration,
        private readonly BehaviorCatalog $catalog,
    ) {}

    public function publishForm(FormDefinition $form, User $actor): FormDefinition
    {
        if ($form->status !== DefinitionStatus::DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only draft forms can be published.']);
        }

        $this->forms->assertDefinition($form->schema_json);
        $checksum = 'sha256:'.hash('sha256', json_encode($form->schema_json, JSON_THROW_ON_ERROR));
        $form->forceFill([
            'status' => DefinitionStatus::PUBLISHED,
            'checksum' => $checksum,
            'published_at' => now(),
        ])->save();

        return $form->fresh();
    }

    public function publish(AnimalBlueprint $blueprint, User $actor): AnimalBlueprint
    {
        if ($blueprint->status !== DefinitionStatus::DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only draft blueprints can be published.']);
        }

        return DB::transaction(function () use ($blueprint, $actor): AnimalBlueprint {
            $species = AnimalSpecies::query()->where('uuid', $blueprint->species_uuid)->firstOrFail();
            $form = FormDefinition::query()
                ->where('uuid', $blueprint->registration_form_uuid)
                ->where('version', $blueprint->registration_form_version)
                ->firstOrFail();

            if ($form->status !== DefinitionStatus::PUBLISHED) {
                throw ValidationException::withMessages(['registration_form_uuid' => 'Registration form must be published first.']);
            }
            $binding = $blueprint->settings_json['catalogBinding'] ?? null;
            if (is_array($binding) && array_filter($binding) !== []) {
                Validator::make($binding, [
                    'livestockTypeId' => ['required', 'integer', 'exists:livestock_types,id'],
                    'speciesId' => ['required', 'integer', 'exists:species,id'],
                ])->validate();
                $typeId = DB::table('species')->where('id', $binding['speciesId'])->value('livestockTypeId');
                if ($typeId !== null && (int) $typeId !== (int) $binding['livestockTypeId']) {
                    throw ValidationException::withMessages(['settings_json.catalogBinding.speciesId' => 'Species must belong to the selected animal type.']);
                }
            }
            $settings = $blueprint->settings_json;
            $modes = $settings['subjectModes'] ?? [];
            if (! is_array($modes) || array_diff($modes, ['individual', 'group']) !== [] || $modes === []) {
                throw ValidationException::withMessages(['settings_json.subjectModes' => 'At least one supported subject mode is required.']);
            }
            $allowedSexCodes = $settings['allowedSexCodes'] ?? [];
            if (! is_array($allowedSexCodes) || array_diff($allowedSexCodes, ['male', 'female', 'intersex', 'unknown', 'not_applicable']) !== []) {
                throw ValidationException::withMessages(['settings_json.allowedSexCodes' => 'One or more sex codes are unsupported.']);
            }
            $policy = $settings['identificationPolicy'] ?? [];
            $allowedIdentifiers = is_array($policy['allowed'] ?? null) ? $policy['allowed'] : [];
            $requiredIdentifiers = is_array($policy['required'] ?? null) ? $policy['required'] : [];
            if (array_diff($requiredIdentifiers, $allowedIdentifiers) !== []) {
                throw ValidationException::withMessages(['settings_json.identificationPolicy.required' => 'Every required identifier must also be allowed.']);
            }
            $knownIdentifierCodes = IdentifierType::query()->where('status', 'active')->pluck('code')->all();
            if (array_diff($allowedIdentifiers, $knownIdentifierCodes) !== []) {
                throw ValidationException::withMessages(['settings_json.identificationPolicy.allowed' => 'The policy references an unknown or inactive identifier type.']);
            }

            $events = EventDefinition::query()
                ->where('blueprint_uuid', $blueprint->uuid)
                ->where('blueprint_version', $blueprint->version)
                ->get();
            foreach ($events as $event) {
                if (EventBehavior::tryFrom($event->behavior_code) === null) {
                    throw ValidationException::withMessages(['events' => "Unsupported event behavior: {$event->behavior_code}"]);
                }
                $eventForm = FormDefinition::query()->where('uuid', $event->form_uuid)->where('version', $event->form_version)->first();
                if (! $eventForm || $eventForm->status !== DefinitionStatus::PUBLISHED) {
                    throw ValidationException::withMessages(['events' => "Event {$event->code} must reference a published form."]);
                }
                $errors = $this->catalog->validateEvent($event->behavior_code, $eventForm->schema_json, $modes, $species->capabilities_json);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['events' => "Event {$event->code}: ".collect($errors)->map(fn ($error) => "{$error['path']}: {$error['code']}")->implode(', ')]);
                }
            }

            $compiled = [
                'speciesCode' => $species->code,
                'subjectModes' => $modes,
                'registrationForm' => $form->code.'@'.$form->version,
                'eventCodes' => $events->pluck('code')->values()->all(),
                'settings' => $settings,
            ];
            $checksum = 'sha256:'.hash('sha256', json_encode($compiled, JSON_THROW_ON_ERROR));

            $blueprint->forceFill([
                'status' => DefinitionStatus::PUBLISHED,
                'compiled_manifest_json' => $compiled,
                'checksum' => $checksum,
                'published_by_uuid' => $actor->uuid,
                'published_at' => now(),
            ])->save();
            $species->forceFill([
                'status' => DefinitionStatus::PUBLISHED,
                'published_at' => $species->published_at ?? now(),
            ])->save();
            EventDefinition::query()
                ->where('blueprint_uuid', $blueprint->uuid)
                ->where('blueprint_version', $blueprint->version)
                ->update(['status' => DefinitionStatus::PUBLISHED->value]);

            $this->configuration->publishRevision($actor->uuid);

            return $blueprint->fresh();
        });
    }

    public function cloneDraft(AnimalBlueprint $blueprint, User $actor): AnimalBlueprint
    {
        return DB::transaction(function () use ($blueprint, $actor): AnimalBlueprint {
            $nextVersion = ((int) AnimalBlueprint::query()->where('species_uuid', $blueprint->species_uuid)->max('version')) + 1;

            $draft = AnimalBlueprint::create([
                'species_uuid' => $blueprint->species_uuid,
                'code' => $blueprint->code,
                'version' => $nextVersion,
                'status' => DefinitionStatus::DRAFT,
                'registration_form_uuid' => $blueprint->registration_form_uuid,
                'registration_form_version' => $blueprint->registration_form_version,
                'settings_json' => $blueprint->settings_json,
                'created_by_uuid' => $actor->uuid,
            ]);
            foreach (EventDefinition::where('blueprint_uuid', $blueprint->uuid)->where('blueprint_version', $blueprint->version)->where('status', '!=', 'retired')->get() as $event) {
                $copy = $event->replicate(['uuid']);
                $copy->forceFill(['uuid' => (string) Str::uuid(), 'blueprint_uuid' => $draft->uuid, 'blueprint_version' => $draft->version, 'status' => 'draft'])->save();
            }

            return $draft;
        });
    }

    public function retire(AnimalBlueprint $blueprint, User $actor): AnimalBlueprint
    {
        if ($blueprint->status !== DefinitionStatus::PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'Only published blueprints can be retired.']);
        }
        $blueprint->forceFill(['status' => DefinitionStatus::RETIRED, 'retired_at' => now()])->save();
        $this->configuration->publishRevision($actor->uuid);

        return $blueprint->fresh();
    }
}
