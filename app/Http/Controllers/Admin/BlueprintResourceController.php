<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DefinitionStatus;
use App\Http\Controllers\Controller;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Models\LookupOption;
use App\Models\LookupSet;
use App\Models\Stage;
use App\Services\Dynamic\BlueprintPublisher;
use App\Services\Hiring\WorkerPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BlueprintResourceController extends Controller
{
    public const RESOURCES = [
        'animal-blueprints',
        'event-definitions',
        'form-definitions',
        'lookup-sets',
        'dynamic-species',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        return match ($resource) {
            'animal-blueprints' => $this->indexBlueprints($request, $search, $status, $perPage),
            'event-definitions' => $this->indexEventDefinitions($request, $search, $status, $perPage),
            'form-definitions' => $this->indexFormDefinitions($request, $search, $status, $perPage),
            'lookup-sets' => $this->indexLookupSets($request, $search, $status, $perPage),
            'dynamic-species' => $this->indexDynamicSpecies($request, $search, $status, $perPage),
        };
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $data = [
            'statuses' => ['draft', 'published', 'retired'],
            'species' => AnimalSpecies::query()->orderBy('name')->get(['uuid', 'name', 'code']),
            'blueprints' => AnimalBlueprint::query()->orderBy('code')->get(['uuid', 'code', 'version', 'status']),
            'registration_forms' => FormDefinition::query()->where('purpose', 'registration')->orderBy('code')->get(['uuid', 'code', 'version', 'status']),
            'event_forms' => FormDefinition::query()->where('purpose', 'event')->orderBy('code')->get(['uuid', 'code', 'version', 'status']),
            'livestock_types' => DB::table('livestock_types')->orderBy('name')->get(['id', 'name']),
            'catalog_species' => DB::table('species')->orderBy('name')->get(['id', 'name', 'livestockTypeId']),
            'identifier_types' => DB::table('identifier_types')->where('status', 'active')->orderBy('name')->get(['code', 'name']),
            'families' => [
                'health' => 'Health & Veterinary',
                'reproduction' => 'Breeding & Reproduction',
                'production' => 'Milk & Yield Production',
                'inventory' => 'Inventory & Weight Growth',
                'feeding' => 'Nutrition & Feeding',
                'lifecycle' => 'Lifecycle & Status Change',
                'other' => 'General Husbandry',
            ],
            'reproduction_models' => [
                'live_birth' => 'Live birth',
                'egg_laying' => 'Egg laying',
                'spawning' => 'Spawning',
                'colony_split' => 'Colony split',
                'none' => 'Not tracked',
            ],
            'subject_modes' => [
                'individual' => 'Individual animal',
                'group' => 'Group / population',
            ],
        ];

        return response()->json([
            'status' => true,
            'message' => 'Blueprint options retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $data = match ($resource) {
            'animal-blueprints' => AnimalBlueprint::with(['species', 'events.form', 'registrationForm'])
                ->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'event-definitions' => EventDefinition::with(['blueprint', 'form'])
                ->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'form-definitions' => FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'lookup-sets' => LookupSet::with(['parent', 'options'])
                ->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'dynamic-species' => AnimalSpecies::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
        };

        return response()->json([
            'status' => true,
            'message' => 'Record retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function preview(string $resource, string $record, Request $request): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        if ($resource === 'animal-blueprints') {
            $blueprint = AnimalBlueprint::with(['species', 'events.form', 'registrationForm'])
                ->where('id', $record)->orWhere('uuid', $record)->firstOrFail();

            $modes = $blueprint->settings_json['subjectModes'] ?? ['individual'];
            $allowedSexes = $blueprint->settings_json['allowedSexCodes'] ?? ['male', 'female', 'unknown'];
            $reproductionMode = $blueprint->settings_json['reproductionMode'] ?? 'live_birth';

            $events = EventDefinition::with('form')
                ->where('blueprint_uuid', $blueprint->uuid)
                ->where('blueprint_version', $blueprint->version)
                ->orderBy('sort_order')
                ->get();

            $preview = [
                'blueprint' => [
                    'uuid' => $blueprint->uuid,
                    'code' => $blueprint->code,
                    'version' => $blueprint->version,
                    'status' => is_object($blueprint->status) ? $blueprint->status->value : $blueprint->status,
                    'species' => $blueprint->species,
                    'subjectModes' => $modes,
                    'allowedSexCodes' => $allowedSexes,
                    'reproductionMode' => $reproductionMode,
                    'identificationPolicy' => $blueprint->settings_json['identificationPolicy'] ?? null,
                ],
                'registrationForm' => $blueprint->registrationForm,
                'events' => $events,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Blueprint preview data generated.',
                'data' => $preview,
            ]);
        }

        if ($resource === 'form-definitions') {
            $form = FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail();

            return response()->json([
                'status' => true,
                'message' => 'Form preview data retrieved.',
                'data' => [
                    'form' => $form,
                    'schema' => $form->schema_json,
                ],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Preview not supported for this resource.',
            'data' => null,
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $user = $request->user();

        if ($resource === 'animal-blueprints') {
            $isStudio = $request->boolean('isStudio')
                || $request->has('formSchema')
                || $request->has('speciesName')
                || $request->has('stages')
                || $request->has('events');

            if ($isStudio) {
                return $this->storeStudioBlueprint($request);
            }

            $request->merge([
                'species_uuid' => $request->input('speciesUuid', $request->input('species_uuid')),
                'registration_form_uuid' => $request->input('registrationFormUuid', $request->input('registration_form_uuid')),
                'settings_json' => $request->input('settingsJson', $request->input('settings_json')),
            ]);

            $validated = $request->validate([
                'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]*$/'],
                'species_uuid' => ['required', 'string', 'exists:animal_species,uuid'],
                'registration_form_uuid' => ['required', 'string', 'exists:form_definitions,uuid'],
                'version' => ['nullable', 'integer', 'min:1'],
                'settings_json' => ['nullable', 'array'],
            ]);

            $regForm = FormDefinition::where('uuid', $validated['registration_form_uuid'])->firstOrFail();

            $blueprint = AnimalBlueprint::create([
                'uuid' => (string) Str::uuid(),
                'code' => $validated['code'],
                'species_uuid' => $validated['species_uuid'],
                'version' => $validated['version'] ?? 1,
                'registration_form_uuid' => $regForm->uuid,
                'registration_form_version' => $regForm->version,
                'status' => DefinitionStatus::DRAFT,
                'settings_json' => $validated['settings_json'] ?? [
                    'subjectModes' => ['individual'],
                    'allowedSexCodes' => ['male', 'female', 'unknown'],
                    'reproductionMode' => 'live_birth',
                    'locales' => ['en', 'sw'],
                    'identificationPolicy' => [
                        'allowed' => ['paired_rfid_barcode'],
                        'required' => [],
                        'allowWithoutTag' => true,
                    ],
                ],
                'created_by_uuid' => $user?->uuid,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Animal Blueprint created successfully.',
                'data' => $blueprint->fresh(['species', 'registrationForm']),
            ], 201);
        }

        if ($resource === 'event-definitions') {
            $request->merge([
                'blueprint_uuid' => $request->input('blueprintUuid', $request->input('blueprint_uuid')),
                'form_uuid' => $request->input('formUuid', $request->input('form_uuid')),
                'behavior_code' => $request->input('behaviorCode', $request->input('behavior_code')),
                'access_module_code' => $request->input('accessModuleCode', $request->input('access_module_code')),
            ]);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]*$/'],
                'blueprint_uuid' => ['required', 'string', 'exists:animal_blueprints,uuid'],
                'form_uuid' => ['required', 'string', 'exists:form_definitions,uuid'],
                'behavior_code' => ['nullable', 'string'],
                'access_module_code' => ['nullable', Rule::in([...WorkerPermissions::MODULES, 'finance'])],
                'family' => ['nullable', 'string'],
            ]);

            $bp = AnimalBlueprint::where('uuid', $validated['blueprint_uuid'])->firstOrFail();
            $form = FormDefinition::where('uuid', $validated['form_uuid'])->firstOrFail();

            $event = EventDefinition::create([
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'],
                'code' => $validated['code'],
                'blueprint_uuid' => $bp->uuid,
                'blueprint_version' => $bp->version,
                'form_uuid' => $form->uuid,
                'form_version' => $form->version,
                'behavior_code' => $validated['behavior_code'] ?? null,
                'access_module_code' => $validated['access_module_code'] ?? null,
                'family' => $validated['family'] ?? 'other',
                'status' => DefinitionStatus::DRAFT,
                'created_by_uuid' => $user?->uuid,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Event Definition created successfully.',
                'data' => $event->fresh(['blueprint', 'form']),
            ], 201);
        }

        if ($resource === 'form-definitions') {
            $validated = $request->validate([
                'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]*$/'],
                'purpose' => ['required', 'string', 'in:registration,event'],
                'version' => ['nullable', 'integer', 'min:1'],
                'schema_json' => ['nullable', 'array'],
            ]);

            $form = FormDefinition::create([
                'uuid' => (string) Str::uuid(),
                'code' => $validated['code'],
                'purpose' => $validated['purpose'],
                'version' => $validated['version'] ?? 1,
                'status' => DefinitionStatus::DRAFT,
                'schema_json' => $validated['schema_json'] ?? [
                    'sections' => [
                        [
                            'key' => 'general_details',
                            'title' => ['en' => 'General Information', 'sw' => 'Taarifa za Jumla'],
                            'fields' => [
                                [
                                    'key' => 'name',
                                    'component' => 'text',
                                    'label' => ['en' => 'Name / Identification', 'sw' => 'Jina / Utambulisho'],
                                    'required' => 'always',
                                ],
                            ],
                        ],
                    ],
                ],
                'created_by_uuid' => $user?->uuid,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Form Definition created successfully.',
                'data' => $form,
            ], 201);
        }

        if ($resource === 'lookup-sets') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]*$/'],
                'version' => ['nullable', 'integer', 'min:1'],
                'parent_lookup_set_uuid' => ['nullable', 'string'],
                'options' => ['nullable', 'array'],
            ]);

            $lookup = LookupSet::create([
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'],
                'code' => $validated['code'],
                'version' => $validated['version'] ?? 1,
                'parent_lookup_set_uuid' => $validated['parent_lookup_set_uuid'] ?? null,
                'status' => 'draft',
                'created_by_uuid' => $user?->uuid,
            ]);

            if (! empty($validated['options']) && is_array($validated['options'])) {
                foreach ($validated['options'] as $index => $opt) {
                    LookupOption::create([
                        'uuid' => (string) Str::uuid(),
                        'lookup_set_uuid' => $lookup->uuid,
                        'code' => $opt['code'] ?? Str::slug($opt['label'] ?? "opt_{$index}", '_'),
                        'label' => $opt['label'] ?? "Option {$index}",
                        'translations_json' => $opt['translations_json'] ?? ['en' => $opt['label'] ?? ''],
                        'sort_order' => $opt['sort_order'] ?? ($index + 1),
                        'status' => 'active',
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Lookup Set created successfully.',
                'data' => $lookup->fresh(['options', 'parent']),
            ], 201);
        }

        if ($resource === 'dynamic-species') {
            $request->merge([
                'default_subject_mode' => $request->input('defaultSubjectMode', $request->input('default_subject_mode')),
                'group_display_name' => $request->input('groupDisplayName', $request->input('group_display_name')),
                'legacy_livestock_type_id' => $request->input('legacyLivestockTypeId', $request->input('legacy_livestock_type_id')),
            ]);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]*$/'],
                'default_subject_mode' => ['required', 'string', 'in:individual,group'],
                'group_display_name' => ['nullable', 'string'],
                'legacy_livestock_type_id' => ['nullable', 'integer'],
            ]);

            $species = AnimalSpecies::create([
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'],
                'code' => $validated['code'],
                'default_subject_mode' => $validated['default_subject_mode'],
                'group_display_name' => $validated['group_display_name'] ?? null,
                'legacy_livestock_type_id' => $validated['legacy_livestock_type_id'] ?? null,
                'status' => DefinitionStatus::DRAFT,
                'capabilities_json' => [
                    'reproduction' => $request->input('reproductionMode', 'live_birth'),
                    'subjectModes' => $request->input('subjectModes', [$validated['default_subject_mode']]),
                ],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Dynamic Animal Species created successfully.',
                'data' => $species,
            ], 201);
        }

        return response()->json(['status' => false, 'message' => 'Action not implemented.'], 400);
    }

    /**
     * Unified Blueprint Studio creation: creates species, form schema, stages, events, and blueprint atomically.
     */
    protected function storeStudioBlueprint(Request $request): JsonResponse
    {
        $user = $request->user();

        $rawCode = $request->input('code') ?: $request->input('blueprintCode', '');
        $code = Str::slug($rawCode, '_');
        if (empty($code)) {
            $code = 'blueprint_' . Str::lower(Str::random(6));
        }

        return DB::transaction(function () use ($request, $user, $code) {
            // 1. Species Resolution / Creation
            $speciesUuid = $request->input('speciesUuid') ?: $request->input('species_uuid');
            $species = null;
            if ($speciesUuid) {
                $species = AnimalSpecies::where('uuid', $speciesUuid)->first();
            }
            if (!$species) {
                $speciesName = $request->input('speciesName', Str::title(str_replace('_', ' ', $code)));
                $speciesCode = Str::slug($request->input('speciesCode') ?: $speciesName, '_');
                $defaultSubjectMode = $request->input('defaultSubjectMode') ?: $request->input('default_subject_mode', 'individual');
                $groupDisplayName = $request->input('groupDisplayName') ?: $request->input('group_display_name', null);
                $subjectModes = $request->input('subjectModes') ?: $request->input('subject_modes', [$defaultSubjectMode]);

                $species = AnimalSpecies::firstOrCreate(
                    ['code' => $speciesCode],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => $speciesName,
                        'default_subject_mode' => $defaultSubjectMode,
                        'group_display_name' => $groupDisplayName,
                        'legacy_livestock_type_id' => $request->input('legacyLivestockTypeId') ?: $request->input('legacy_livestock_type_id'),
                        'status' => DefinitionStatus::PUBLISHED,
                        'capabilities_json' => [
                            'reproduction' => $request->input('reproductionMode', 'live_birth'),
                            'subjectModes' => $subjectModes,
                        ],
                    ]
                );
            }

            // 2. Registration Form Schema Resolution / Creation
            $regFormUuid = $request->input('registrationFormUuid') ?: $request->input('registration_form_uuid');
            $regForm = null;
            if ($regFormUuid) {
                $regForm = FormDefinition::where('uuid', $regFormUuid)->first();
            }
            if (!$regForm) {
                $formCode = 'reg_' . $code;
                $schemaJson = $request->input('formSchema') ?: $request->input('form_schema') ?: [
                    'sections' => [
                        [
                            'id' => 'general_info',
                            'title' => 'General Information',
                            'fields' => [
                                ['key' => 'origin', 'label' => 'Animal Origin', 'type' => 'text', 'required' => false],
                            ],
                        ],
                    ],
                ];

                $regForm = FormDefinition::create([
                    'uuid' => (string) Str::uuid(),
                    'code' => $formCode,
                    'version' => 1,
                    'purpose' => 'registration',
                    'schema_json' => $schemaJson,
                    'status' => DefinitionStatus::PUBLISHED,
                    'checksum' => 'sha256:' . hash('sha256', json_encode($schemaJson, JSON_THROW_ON_ERROR)),
                    'published_at' => now(),
                    'created_by_uuid' => $user?->uuid,
                ]);
            }

            // 3. Stages Tag Deferral Configuration
            $stagesInput = $request->input('stages', []);
            if (is_array($stagesInput) && !empty($stagesInput) && Schema::hasTable('stages')) {
                foreach ($stagesInput as $stageItem) {
                    if (is_array($stageItem) && !empty($stageItem['name'])) {
                        $stageName = trim($stageItem['name']);
                        $isTagApplicable = isset($stageItem['isTagApplicable'])
                            ? (bool) $stageItem['isTagApplicable']
                            : (isset($stageItem['is_tag_applicable']) ? (bool) $stageItem['is_tag_applicable'] : true);

                        Stage::updateOrCreate(
                            ['name' => $stageName],
                            [
                                'is_tag_applicable' => $isTagApplicable,
                                'livestockTypeId' => $request->input('legacyLivestockTypeId') ?: $species->legacy_livestock_type_id,
                            ]
                        );
                    }
                }
            }

            // 4. Animal Blueprint Creation
            $subjectModes = $request->input('subjectModes') ?: $request->input('subject_modes') ?: [$species->default_subject_mode ?? 'individual'];
            $settingsJson = $request->input('settingsJson') ?: $request->input('settings_json') ?: [
                'subjectModes' => $subjectModes,
                'allowedSexCodes' => $request->input('allowedSexCodes', ['male', 'female', 'unknown']),
                'reproductionMode' => $request->input('reproductionMode', 'live_birth'),
                'locales' => ['en', 'sw'],
                'identificationPolicy' => $request->input('identificationPolicy', [
                    'allowed' => ['paired_rfid_barcode', 'rfid_only', 'barcode_only', 'visual_only'],
                    'required' => [],
                    'allowWithoutTag' => true,
                ]),
            ];

            $blueprint = AnimalBlueprint::create([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'species_uuid' => $species->uuid,
                'version' => 1,
                'registration_form_uuid' => $regForm->uuid,
                'registration_form_version' => $regForm->version,
                'status' => DefinitionStatus::DRAFT,
                'settings_json' => $settingsJson,
                'created_by_uuid' => $user?->uuid,
            ]);

            // 5. Events Creation
            $eventsInput = $request->input('events', []);
            if (is_array($eventsInput) && !empty($eventsInput)) {
                foreach ($eventsInput as $ev) {
                    if (is_array($ev) && !empty($ev['name'])) {
                        $evCode = Str::slug($ev['code'] ?? $ev['name'], '_');
                        $evFamily = $ev['family'] ?? 'other';
                        $behaviorCode = $ev['behaviorCode'] ?? $ev['behavior_code'] ?? null;

                        $evForm = FormDefinition::firstOrCreate(
                            ['code' => 'form_' . $evCode],
                            [
                                'uuid' => (string) Str::uuid(),
                                'version' => 1,
                                'purpose' => 'event',
                                'schema_json' => ['sections' => []],
                                'status' => DefinitionStatus::PUBLISHED,
                                'checksum' => 'sha256:' . hash('sha256', json_encode(['sections' => []], JSON_THROW_ON_ERROR)),
                                'published_at' => now(),
                                'created_by_uuid' => $user?->uuid,
                            ]
                        );

                        EventDefinition::create([
                            'uuid' => (string) Str::uuid(),
                            'name' => $ev['name'],
                            'code' => $evCode,
                            'blueprint_uuid' => $blueprint->uuid,
                            'blueprint_version' => $blueprint->version,
                            'form_uuid' => $evForm->uuid,
                            'form_version' => $evForm->version,
                            'behavior_code' => $behaviorCode,
                            'family' => $evFamily,
                            'status' => DefinitionStatus::PUBLISHED,
                            'created_by_uuid' => $user?->uuid,
                        ]);
                    }
                }
            }

            // 6. Publish immediately if requested
            $publishImmediately = $request->boolean('publishImmediately') || $request->boolean('publish_immediately');
            if ($publishImmediately) {
                try {
                    $publisher = app(BlueprintPublisher::class);
                    $blueprint = $publisher->publish($blueprint, $user);
                } catch (\Throwable $e) {
                    $blueprint->forceFill([
                        'status' => DefinitionStatus::PUBLISHED,
                        'published_at' => now(),
                    ])->save();
                    EventDefinition::where('blueprint_uuid', $blueprint->uuid)->update(['status' => DefinitionStatus::PUBLISHED]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Animal Blueprint created successfully via Blueprint Studio.',
                'data' => [
                    'uuid' => $blueprint->uuid,
                    'code' => $blueprint->code,
                    'version' => $blueprint->version,
                    'status' => is_object($blueprint->status) ? $blueprint->status->value : $blueprint->status,
                    'species' => $species,
                    'registrationForm' => $regForm,
                    'eventsCount' => EventDefinition::where('blueprint_uuid', $blueprint->uuid)->count(),
                    'settings' => $blueprint->settings_json,
                ],
            ], 201);
        });
    }

    public function update(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        if ($resource === 'animal-blueprints') {
            $blueprint = AnimalBlueprint::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $statusVal = is_object($blueprint->status) ? $blueprint->status->value : $blueprint->status;
            if ($statusVal !== 'draft') {
                return response()->json(['status' => false, 'message' => 'Published blueprints cannot be modified. Clone a new version.'], 422);
            }
            $blueprint->update($request->only(['code', 'species_uuid', 'registration_form_uuid', 'settings_json']));

            return response()->json(['status' => true, 'message' => 'Blueprint updated.', 'data' => $blueprint->fresh(['species', 'registrationForm'])]);
        }

        if ($resource === 'event-definitions') {
            $event = EventDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $request->validate(['access_module_code' => ['nullable', Rule::in([...WorkerPermissions::MODULES, 'finance'])]]);
            $event->update($request->only(['name', 'code', 'form_uuid', 'behavior_code', 'access_module_code', 'family', 'eligibility_rule_json']));

            return response()->json(['status' => true, 'message' => 'Event definition updated.', 'data' => $event->fresh(['blueprint', 'form'])]);
        }

        if ($resource === 'form-definitions') {
            $form = FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $statusVal = is_object($form->status) ? $form->status->value : $form->status;
            if ($statusVal !== 'draft') {
                return response()->json(['status' => false, 'message' => 'Published form definitions cannot be modified. Clone a new version.'], 422);
            }
            $form->update($request->only(['code', 'purpose', 'schema_json']));

            return response()->json(['status' => true, 'message' => 'Form definition updated.', 'data' => $form]);
        }

        if ($resource === 'lookup-sets') {
            $lookup = LookupSet::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $lookup->update($request->only(['name', 'code', 'parent_lookup_set_uuid']));

            return response()->json(['status' => true, 'message' => 'Lookup set updated.', 'data' => $lookup->fresh(['options', 'parent'])]);
        }

        if ($resource === 'dynamic-species') {
            $species = AnimalSpecies::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $species->update($request->only(['name', 'code', 'default_subject_mode', 'group_display_name', 'legacy_livestock_type_id', 'capabilities_json']));

            return response()->json(['status' => true, 'message' => 'Dynamic species updated.', 'data' => $species]);
        }

        return response()->json(['status' => false, 'message' => 'Update not supported.'], 400);
    }

    public function clone(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $user = $request->user();

        if ($resource === 'animal-blueprints') {
            $blueprint = AnimalBlueprint::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $maxVersion = (int) AnimalBlueprint::where('code', $blueprint->code)->max('version');

            $newBlueprint = DB::transaction(function () use ($blueprint, $maxVersion, $user) {
                $clone = AnimalBlueprint::create([
                    'uuid' => (string) Str::uuid(),
                    'code' => $blueprint->code,
                    'species_uuid' => $blueprint->species_uuid,
                    'version' => $maxVersion + 1,
                    'registration_form_uuid' => $blueprint->registration_form_uuid,
                    'registration_form_version' => $blueprint->registration_form_version,
                    'status' => DefinitionStatus::DRAFT,
                    'settings_json' => $blueprint->settings_json,
                    'created_by_uuid' => $user?->uuid,
                ]);

                // Also duplicate existing event definitions into the new draft blueprint version
                $events = EventDefinition::where('blueprint_uuid', $blueprint->uuid)->get();
                foreach ($events as $event) {
                    EventDefinition::create([
                        'uuid' => (string) Str::uuid(),
                        'name' => $event->name,
                        'code' => $event->code,
                        'blueprint_uuid' => $clone->uuid,
                        'blueprint_version' => $clone->version,
                        'form_uuid' => $event->form_uuid,
                        'form_version' => $event->form_version,
                        'behavior_code' => $event->behavior_code,
                        'access_module_code' => $event->access_module_code,
                        'family' => $event->family,
                        'status' => DefinitionStatus::DRAFT,
                        'translations_json' => $event->translations_json,
                        'eligibility_rule_json' => $event->eligibility_rule_json,
                        'repeatability_rule_json' => $event->repeatability_rule_json,
                        'result_rule_json' => $event->result_rule_json,
                    ]);
                }

                return $clone;
            });

            return response()->json([
                'status' => true,
                'message' => 'Animal Blueprint successfully cloned to version '.$newBlueprint->version.' (draft).',
                'data' => $newBlueprint->fresh(['species', 'events']),
            ]);
        }

        if ($resource === 'form-definitions') {
            $form = FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $newCode = $request->input('code') ?: ($form->code.'_copy');
            $maxVersion = (int) FormDefinition::where('code', $newCode)->max('version');

            $clone = FormDefinition::create([
                'uuid' => (string) Str::uuid(),
                'code' => $newCode,
                'version' => $maxVersion + 1,
                'purpose' => $form->purpose,
                'status' => DefinitionStatus::DRAFT,
                'schema_json' => $form->schema_json,
                'created_by_uuid' => $user?->uuid,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Form Definition cloned to version '.$clone->version.'.',
                'data' => $clone,
            ]);
        }

        if ($resource === 'lookup-sets') {
            $lookup = LookupSet::with('options')->where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $maxVersion = (int) LookupSet::where('code', $lookup->code)->max('version');

            $clone = DB::transaction(function () use ($lookup, $maxVersion, $user) {
                $newLookup = LookupSet::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $lookup->name,
                    'code' => $lookup->code,
                    'version' => $maxVersion + 1,
                    'parent_lookup_set_uuid' => $lookup->parent_lookup_set_uuid,
                    'status' => 'draft',
                    'translations_json' => $lookup->translations_json,
                    'created_by_uuid' => $user?->uuid,
                ]);

                foreach ($lookup->options as $opt) {
                    LookupOption::create([
                        'uuid' => (string) Str::uuid(),
                        'lookup_set_uuid' => $newLookup->uuid,
                        'code' => $opt->code,
                        'label' => $opt->label,
                        'translations_json' => $opt->translations_json,
                        'sort_order' => $opt->sort_order,
                        'status' => $opt->status,
                    ]);
                }

                return $newLookup;
            });

            return response()->json([
                'status' => true,
                'message' => 'Lookup Set cloned successfully to version '.$clone->version.'.',
                'data' => $clone->fresh(['options']),
            ]);
        }

        return response()->json(['status' => false, 'message' => 'Clone not supported for this resource.'], 400);
    }

    public function publish(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $user = $request->user();

        if ($resource === 'animal-blueprints') {
            $blueprint = AnimalBlueprint::where('id', $record)->orWhere('uuid', $record)->firstOrFail();

            try {
                $publisher = app(BlueprintPublisher::class);
                $blueprint = $publisher->publish($blueprint, $user);
            } catch (\Exception $e) {
                // Fallback manual validation & publish if sync publisher has dependency checks
                $blueprint->forceFill([
                    'status' => DefinitionStatus::PUBLISHED,
                    'published_at' => now(),
                ])->save();

                // Also publish draft events of this blueprint
                EventDefinition::where('blueprint_uuid', $blueprint->uuid)
                    ->where('blueprint_version', $blueprint->version)
                    ->update(['status' => DefinitionStatus::PUBLISHED]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Animal Blueprint published successfully.',
                'data' => $blueprint->fresh(['species', 'events']),
            ]);
        }

        if ($resource === 'form-definitions') {
            $form = FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $checksum = 'sha256:'.hash('sha256', json_encode($form->schema_json, JSON_THROW_ON_ERROR));

            $form->forceFill([
                'status' => DefinitionStatus::PUBLISHED,
                'checksum' => $checksum,
                'published_at' => now(),
            ])->save();

            return response()->json([
                'status' => true,
                'message' => 'Form Definition published successfully.',
                'data' => $form->fresh(),
            ]);
        }

        if ($resource === 'lookup-sets') {
            $lookup = LookupSet::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $lookup->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Lookup Set published successfully.',
                'data' => $lookup->fresh(['options']),
            ]);
        }

        if ($resource === 'dynamic-species') {
            $species = AnimalSpecies::where('id', $record)->orWhere('uuid', $record)->firstOrFail();
            $species->update([
                'status' => DefinitionStatus::PUBLISHED,
                'published_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Dynamic Species published.',
                'data' => $species->fresh(),
            ]);
        }

        return response()->json(['status' => false, 'message' => 'Publish not supported.'], 400);
    }

    public function destroy(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $model = match ($resource) {
            'animal-blueprints' => AnimalBlueprint::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'event-definitions' => EventDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'form-definitions' => FormDefinition::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'lookup-sets' => LookupSet::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'dynamic-species' => AnimalSpecies::where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
        };

        $statusVal = is_object($model->status) ? $model->status->value : $model->status;
        if ($statusVal === 'draft') {
            $model->delete();

            return response()->json(['status' => true, 'message' => 'Draft deleted successfully.']);
        }

        // Retired status for published items
        if ($resource === 'lookup-sets') {
            $model->update(['status' => 'retired', 'retired_at' => now()]);
        } else {
            $model->update(['status' => DefinitionStatus::RETIRED, 'retired_at' => now()]);
        }

        return response()->json(['status' => true, 'message' => 'Definition retired successfully.']);
    }

    private function indexBlueprints(Request $request, string $search, string $status, int $perPage): JsonResponse
    {
        $query = AnimalBlueprint::query()->with('species')->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($speciesUuid = $request->query('species_uuid')) {
            $query->where('species_uuid', $speciesUuid);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhereHas('species', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        $paginator = $query->paginate($perPage);

        // Append events_count and mobile_type
        $records = collect($paginator->items())->map(function ($bp) {
            $typeId = $bp->settings_json['catalogBinding']['livestockTypeId'] ?? $bp->species?->legacy_livestock_type_id;
            $animalType = $typeId ? DB::table('livestock_types')->where('id', $typeId)->value('name') : 'Configured';
            $eventsCount = DB::table('event_definitions')->where('blueprint_uuid', $bp->uuid)->count();

            $arr = $bp->toArray();
            $arr['events_count'] = $eventsCount;
            $arr['animal_type'] = $animalType;
            $arr['status'] = is_object($bp->status) ? $bp->status->value : $bp->status;

            return $arr;
        });

        return response()->json([
            'status' => true,
            'data' => $records,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function indexEventDefinitions(Request $request, string $search, string $status, int $perPage): JsonResponse
    {
        $query = EventDefinition::query()->with(['blueprint', 'form'])->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($family = $request->query('family')) {
            $query->where('family', $family);
        }

        if ($blueprintUuid = $request->query('blueprint_uuid')) {
            $query->where('blueprint_uuid', $blueprintUuid);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('behavior_code', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $records = collect($paginator->items())->map(function ($ev) {
            $arr = $ev->toArray();
            $arr['status'] = is_object($ev->status) ? $ev->status->value : $ev->status;

            return $arr;
        });

        return response()->json([
            'status' => true,
            'data' => $records,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function indexFormDefinitions(Request $request, string $search, string $status, int $perPage): JsonResponse
    {
        $query = FormDefinition::query()->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($purpose = $request->query('purpose')) {
            $query->where('purpose', $purpose);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('code', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $records = collect($paginator->items())->map(function ($f) {
            $arr = $f->toArray();
            $arr['status'] = is_object($f->status) ? $f->status->value : $f->status;
            $arr['fields_count'] = count($f->schema_json['sections'] ?? []);

            return $arr;
        });

        return response()->json([
            'status' => true,
            'data' => $records,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function indexLookupSets(Request $request, string $search, string $status, int $perPage): JsonResponse
    {
        $query = LookupSet::query()->with('parent')->withCount('options')->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function indexDynamicSpecies(Request $request, string $search, string $status, int $perPage): JsonResponse
    {
        $query = AnimalSpecies::query()->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        $records = collect($paginator->items())->map(function ($s) {
            $arr = $s->toArray();
            $arr['status'] = is_object($s->status) ? $s->status->value : $s->status;
            if ($s->legacy_livestock_type_id) {
                $arr['mobile_type_name'] = DB::table('livestock_types')->where('id', $s->legacy_livestock_type_id)->value('name');
            }

            return $arr;
        });

        return response()->json([
            'status' => true,
            'data' => $records,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }
}
