<?php

namespace App\Services\Dynamic;

use App\Enums\DefinitionStatus;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\ConfigurationRevision;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Models\IdentifierType;
use App\Models\LookupSet;
use App\Services\Ledger\AppVersionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigurationSyncService
{
    public function publishRevision(?string $actorUuid = null): ConfigurationRevision
    {
        return DB::transaction(function () use ($actorUuid): ConfigurationRevision {
            $revision = ((int) ConfigurationRevision::max('revision')) + 1;
            $manifest = $this->compile($revision);
            unset($manifest['checksum']);
            $checksum = 'sha256:'.hash('sha256', $this->canonicalJson($manifest));
            $manifest['checksum'] = $checksum;

            $published = ConfigurationRevision::create([
                'revision' => $revision,
                'schema_version' => '1',
                'checksum' => $checksum,
                'manifest_json' => $manifest,
                'published_by_uuid' => $actorUuid,
                'published_at' => now(),
            ]);
            DB::table('configuration_publish_requests')->whereNull('fulfilled_at')
                ->update(['revision' => $revision, 'fulfilled_at' => now(), 'updated_at' => now()]);

            return $published;
        });
    }

    /** Compiles one revision for every change the Portal queued since the last one. */
    public function publishPending(): ?ConfigurationRevision
    {
        if (! DB::table('configuration_publish_requests')->whereNull('fulfilled_at')->exists()) {
            return null;
        }

        return DB::transaction(function (): ?ConfigurationRevision {
            $requests = DB::table('configuration_publish_requests')->whereNull('fulfilled_at')->orderBy('id')->lockForUpdate()->get();

            return $requests->isEmpty() ? null : $this->publishRevision($requests->last()->requested_by_uuid);
        });
    }

    public function latestManifest(): array
    {
        $this->publishPending();
        $revision = ConfigurationRevision::query()->latest('revision')->first();

        if ($revision) {
            return $revision->manifest_json;
        }

        $manifest = $this->compile(0);
        unset($manifest['checksum']);
        $manifest['checksum'] = 'sha256:'.hash('sha256', $this->canonicalJson($manifest));

        return $manifest;
    }

    public function compile(int $revision): array
    {
        $forms = FormDefinition::query()->where('status', DefinitionStatus::PUBLISHED->value)->orderBy('code')->orderBy('version')->get();
        $blueprints = AnimalBlueprint::query()->where('status', DefinitionStatus::PUBLISHED->value)->orderBy('code')->orderBy('version')->get();

        return [
            'schemaVersion' => 1,
            'revision' => $revision,
            'generatedAt' => now()->toIso8601String(),
            'minimumAppVersion' => AppVersionPolicy::current()['version'],
            'checksum' => null,
            'locales' => ['en', 'sw'],
            'species' => AnimalSpecies::query()->where('status', DefinitionStatus::PUBLISHED->value)->orderBy('code')->get()->map(fn ($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'translations' => $item->translations_json,
                'translations_json' => $item->translations_json,
                'defaultSubjectMode' => $item->default_subject_mode,
                'default_subject_mode' => $item->default_subject_mode,
                'groupDisplayName' => $item->group_display_name,
                'group_display_name' => $item->group_display_name,
                'legacyLivestockTypeId' => $item->legacy_livestock_type_id,
                'capabilities' => $item->capabilities_json,
                'subjectModes' => $item->capabilities_json['subjectModes'] ?? [$item->default_subject_mode ?? 'individual'],
            ])->values()->all(),
            'behaviors' => app(BehaviorCatalog::class)->manifest(),
            'families' => $this->families(),
            'catalogBindings' => $this->catalogBindings($blueprints),
            // Identity only: lets a new device resolve the lineage of an animal
            // registered with a retired blueprint, without offering it for registration.
            'blueprintLineages' => AnimalBlueprint::query()->whereIn('status', [DefinitionStatus::PUBLISHED->value, DefinitionStatus::RETIRED->value])->orderBy('code')->orderBy('version')->get()->map(fn ($item) => [
                'uuid' => $item->uuid, 'version' => $item->version, 'code' => $item->code, 'speciesUuid' => $item->species_uuid,
            ])->values()->all(),
            'blueprints' => $blueprints->map(fn ($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'speciesUuid' => $item->species_uuid,
                'version' => $item->version,
                'registrationForm' => $item->registration_form_uuid.'@'.$item->registration_form_version,
                'settings' => $item->settings_json,
            ])->values()->all(),
            'forms' => $forms->map(fn ($item) => [
                'uuid' => $item->uuid,
                'id' => $item->code.'@'.$item->version,
                'code' => $item->code,
                'version' => $item->version,
                'purpose' => $item->purpose,
                'schema' => $item->schema_json,
                'checksum' => $item->checksum,
                'minimumAppSchemaVersion' => $item->minimum_app_schema_version,
            ])->values()->all(),
            'events' => EventDefinition::query()->where('status', DefinitionStatus::PUBLISHED->value)->orderBy('sort_order')->get()->map(fn ($item) => [
                'uuid' => $item->uuid,
                'blueprintUuid' => $item->blueprint_uuid,
                'blueprintVersion' => $item->blueprint_version,
                'code' => $item->code,
                'name' => $item->name,
                'translations' => $item->translations_json,
                'form' => $item->form_uuid.'@'.$item->form_version,
                'behavior' => $item->behavior_code,
                'accessModuleCode' => $item->access_module_code,
                'family' => $item->family ?? DB::table('behavior_definitions')->where('code', $item->behavior_code)->value('family'),
                'eligibility' => $item->eligibility_rule_json,
                'repeatability' => $item->repeatability_rule_json,
                'legacyLogType' => $item->result_rule_json['legacyLogType'] ?? match ($item->code) {
                    'record_weight' => 'weightChange', 'record_vaccination' => 'vaccination', default => null,
                },
            ])->values()->all(),
            'identifierTypes' => IdentifierType::query()->where('status', 'active')->orderBy('code')->get()->map(fn ($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'translations' => $item->translations_json,
                'supportedSubjectModes' => $item->supported_subject_modes,
                'normalizationRule' => $item->normalization_rule,
                'uniquenessScope' => $item->uniqueness_scope,
            ])->values()->all(),
            'lookups' => LookupSet::query()
                ->with(['parent', 'options' => fn ($query) => $query->with('parent')->orderBy('sort_order')])
                ->where('status', DefinitionStatus::PUBLISHED->value)
                ->orderBy('code')->orderByDesc('version')
                ->get()
                ->unique('code')->sortBy('code')->values()
                ->map(fn ($set) => [
                    'uuid' => $set->uuid,
                    'code' => $set->code,
                    'version' => $set->version,
                    'name' => $set->name,
                    'translations' => $set->translations_json,
                    'parentLookupCode' => $set->parent?->code,
                    'options' => $set->options->map(fn ($option) => array_filter([
                        'uuid' => $option->uuid,
                        'value' => $option->code,
                        'label' => array_replace(['en' => $option->label], array_filter($option->translations_json ?? [], fn ($text) => is_string($text) && trim($text) !== '')),
                        'legacyId' => $option->legacy_id === null ? null : (int) $option->legacy_id,
                        'parentValue' => $option->parent?->code,
                        'selectable' => $option->status === 'active',
                    ], fn ($value) => $value !== null))->values()->all(),
                ])->values()->all(),
            'tombstones' => [],
        ];
    }

    /** @return array<string, string> family => legacy | ledger_shadow | ledger | retired */
    private function families(): array
    {
        return DB::table('platform_feature_flags')->where('key', 'like', 'family.%.authority')->orderBy('key')->get()
            ->mapWithKeys(fn ($flag) => [str($flag->key)->between('family.', '.authority')->toString() => json_decode((string) $flag->value_json, true)['authority'] ?? 'legacy'])
            ->all();
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function catalogBindings($blueprints): array
    {
        $bindings = [];
        foreach ($blueprints as $blueprint) {
            $binding = $blueprint->settings_json['catalogBinding'] ?? null;
            if (is_array($binding) && isset($binding['speciesId'], $binding['livestockTypeId'])) {
                $bindings[] = [
                    'speciesId' => (int) $binding['speciesId'],
                    'livestockTypeId' => (int) $binding['livestockTypeId'],
                    'blueprintUuid' => $blueprint->uuid, 'blueprintVersion' => (int) $blueprint->version,
                ];
            }
        }
        // The upgrade ledger already records authoritative legacy/canonical identity.
        // Export catalog relationships, never animal data or translated-name guesses.
        if (Schema::hasTable('legacy_record_mappings')) {
            $mapped = DB::table('legacy_record_mappings as m')
                ->join('livestocks as l', 'l.id', '=', 'm.source_key')
                ->join('animal_subjects as s', 's.uuid', '=', 'm.target_uuid')
                ->where('m.source_table', 'livestocks')->where('m.target_type', 'animal_subject')
                ->select('l.speciesId', 'l.livestockTypeId', 's.species_uuid')->distinct()->get();
            foreach ($mapped as $row) {
                if (collect($bindings)->contains(fn ($b) => $b['speciesId'] === (int) $row->speciesId && $b['livestockTypeId'] === (int) $row->livestockTypeId)) {
                    continue;
                }
                $candidates = $blueprints->where('species_uuid', $row->species_uuid)->sortByDesc('version');
                $blueprint = $candidates->first();
                if ($blueprint) {
                    $bindings[] = ['speciesId' => (int) $row->speciesId, 'livestockTypeId' => (int) $row->livestockTypeId,
                        'blueprintUuid' => $blueprint->uuid, 'blueprintVersion' => (int) $blueprint->version];
                }
            }
        }

        return $bindings;
    }
}
