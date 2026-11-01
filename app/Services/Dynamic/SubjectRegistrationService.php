<?php

namespace App\Services\Dynamic;

use App\Enums\DefinitionStatus;
use App\Enums\SexCode;
use App\Enums\SubjectUnitType;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSubject;
use App\Models\FormDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectRegistrationService
{
    public function __construct(
        private readonly FormSchemaValidator $forms,
        private readonly IdentifierAssignmentService $identifiers,
        private readonly RegistrationContext $context,
    ) {}

    public function register(array $input, User $actor): AnimalSubject
    {
        if (! empty($input['uuid'])) {
            $existing = AnimalSubject::withTrashed()->where('uuid', $input['uuid'])->first();
            if ($existing) {
                if ($existing->trashed()) {
                    throw ValidationException::withMessages(['uuid' => 'RECORD_DELETED']);
                }
                if ($existing->farm_uuid !== ($input['farmUuid'] ?? null)) {
                    throw ValidationException::withMessages(['uuid' => 'IDEMPOTENCY_SCOPE_MISMATCH']);
                }

                return $existing;
            }
        }

        $blueprint = AnimalBlueprint::query()
            ->where('uuid', $input['blueprintUuid'] ?? null)
            ->where('version', $input['blueprintVersion'] ?? null)
            ->where('status', DefinitionStatus::PUBLISHED->value)
            ->firstOrFail();
        $form = FormDefinition::query()
            ->where('uuid', $blueprint->registration_form_uuid)
            ->where('version', $blueprint->registration_form_version)
            ->where('status', DefinitionStatus::PUBLISHED->value)
            ->firstOrFail();
        $registrationContext = $this->context->validate($input['registrationContext'] ?? null, $blueprint);
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $this->forms->assertPayload($form->schema_json, $payload);

        $unitType = SubjectUnitType::tryFrom((string) ($input['unitType'] ?? ''));
        if (! $unitType || ! in_array($unitType->value, $blueprint->settings_json['subjectModes'] ?? [], true)) {
            throw ValidationException::withMessages(['unitType' => 'Subject mode is not enabled by this blueprint.']);
        }
        $sex = isset($input['sexCode']) ? SexCode::tryFrom((string) $input['sexCode']) : null;
        if ($unitType === SubjectUnitType::GROUP && $sex && $sex !== SexCode::NOT_APPLICABLE) {
            throw ValidationException::withMessages(['sexCode' => 'Groups use sex composition, not an individual sex value.']);
        }
        if ($unitType === SubjectUnitType::GROUP && ((int) ($input['quantity'] ?? 0)) < 1) {
            throw ValidationException::withMessages(['quantity' => 'Group quantity must be at least one.']);
        }

        return DB::transaction(function () use ($input, $actor, $blueprint, $unitType, $sex, $payload, $registrationContext): AnimalSubject {
            $subject = AnimalSubject::create([
                'uuid' => $input['uuid'] ?? null,
                'farm_uuid' => $input['farmUuid'],
                'species_uuid' => $blueprint->species_uuid,
                'blueprint_uuid' => $blueprint->uuid,
                'blueprint_version' => $blueprint->version,
                'unit_type' => $unitType,
                'display_name' => $input['displayName'] ?? null,
                'birth_or_start_date' => $input['birthOrStartDate'] ?? null,
                'sex_code' => $sex,
                'quantity' => $unitType === SubjectUnitType::GROUP ? $input['quantity'] : null,
                'quantity_unit' => $unitType === SubjectUnitType::GROUP ? ($input['quantityUnit'] ?? 'animals') : null,
                'male_count' => $input['maleCount'] ?? null,
                'female_count' => $input['femaleCount'] ?? null,
                'unknown_sex_count' => $input['unknownSexCount'] ?? null,
                'composition_status' => $input['compositionStatus'] ?? null,
                'current_stage_code' => $input['stageCode'] ?? null,
                'lifecycle_status' => 'active',
                'attributes_json' => $payload,
                'registration_context_json' => $registrationContext,
                'revision' => 1,
                'created_by_uuid' => $actor->uuid,
                'updated_by_uuid' => $actor->uuid,
            ]);

            $paired = $input['pairedTag'] ?? null;
            if ($paired !== null) {
                $this->identifiers->assignPairedBarcodeRfid($subject, (string) $paired, (string) $paired, $actor);
            }
            foreach ($input['identifiers'] ?? [] as $identifier) {
                $this->identifiers->assign($subject, $identifier['typeCode'], $identifier['value'], $actor);
            }

            return $subject;
        });
    }
}
