<?php

namespace App\Services\Dynamic;

use App\Models\AnimalBlueprint;
use App\Models\AnimalSubject;
use App\Models\FormDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubjectUpdateService
{
    public function __construct(private readonly FormSchemaValidator $forms, private readonly RegistrationContext $context, private readonly IdentifierAssignmentService $identifiers) {}

    public function update(AnimalSubject $subject, array $input, User $actor): array
    {
        return DB::transaction(function () use ($subject, $input, $actor): array {
            $locked = AnimalSubject::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = DB::table('dynamic_mutation_receipts')->where('command_uuid', $input['idempotencyUuid'])->first();
            if ($receipt) {
                if ($receipt->subject_uuid !== $locked->uuid || $receipt->actor_uuid !== $actor->uuid || $receipt->fingerprint !== $fingerprint) {
                    throw ValidationException::withMessages(['idempotencyUuid' => 'IDEMPOTENCY_KEY_REUSED']);
                }

                return json_decode($receipt->response_json, true, flags: JSON_THROW_ON_ERROR);
            }
            if ((int) $input['baseRevision'] !== (int) $locked->revision) {
                throw ValidationException::withMessages(['baseRevision' => 'RECORD_REVISION_CONFLICT']);
            }
            $blueprint = AnimalBlueprint::where('uuid', $locked->blueprint_uuid)->where('version', $locked->blueprint_version)->firstOrFail();
            $form = FormDefinition::where('uuid', $blueprint->registration_form_uuid)->where('version', $blueprint->registration_form_version)->firstOrFail();
            $this->forms->assertPayload($form->schema_json, $input['payload']);
            $context = $this->context->validate($input['registrationContext'] ?? null, $blueprint);
            if (isset($input['quantity']) && $locked->unit_type->value !== 'group') {
                throw ValidationException::withMessages(['quantity' => 'QUANTITY_REQUIRES_GROUP']);
            }
            $before = $locked->attributesToArray();
            $locked->forceFill([
                'attributes_json' => $input['payload'],
                'quantity' => $input['quantity'] ?? $locked->quantity,
                'registration_context_json' => $context ?? $locked->registration_context_json,
                'display_name' => $context['name'] ?? $locked->display_name,
                'sex_code' => $context['gender'] ?? $locked->sex_code,
                'birth_or_start_date' => $context['dateOfBirth'] ?? $locked->birth_or_start_date,
                'revision' => ((int) $locked->revision) + 1,
                'updated_by_uuid' => $actor->uuid,
            ])->save();
            if (array_key_exists('pairedTag', $input)) {
                $required = $blueprint->settings_json['identificationPolicy']['required'] ?? [];
                if (blank($input['pairedTag']) && in_array('paired_rfid_barcode', $required, true)) {
                    throw ValidationException::withMessages(['pairedTag' => 'REQUIRED_IDENTIFIER_MISSING']);
                }
                $this->identifiers->replacePaired($locked, $input['pairedTag'], $actor);
            }
            $response = $locked->fresh()->load('identifiers.type')->toArray();
            DB::table('dynamic_mutation_receipts')->insert([
                'command_uuid' => $input['idempotencyUuid'], 'subject_uuid' => $locked->uuid,
                'actor_uuid' => $actor->uuid, 'fingerprint' => $fingerprint,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(), 'actor_uuid' => $actor->uuid,
                'action' => 'animal_subject.updated', 'entity_type' => 'animal_subject', 'entity_uuid' => $locked->uuid,
                'before_json' => json_encode($before), 'after_json' => json_encode($response),
                'request_uuid' => $input['idempotencyUuid'], 'created_at' => now(),
            ]);

            return $response;
        });
    }
}
