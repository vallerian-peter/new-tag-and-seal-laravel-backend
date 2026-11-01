<?php

namespace App\Services\Dynamic;

use App\Models\LookupOption;
use App\Models\LookupSet;
use Illuminate\Validation\ValidationException;

class FormSchemaValidator
{
    public const COMPONENTS = [
        'text', 'multiline_text', 'integer', 'decimal', 'date', 'date_time',
        'boolean', 'single_select', 'multi_select', 'measurement', 'photo',
        'file', 'identifier_scanner', 'subject_reference', 'location',
        'repeatable_group',
    ];

    /** Components the current mobile runtime renders and submits; others cannot be published yet. */
    public const MOBILE_COMPONENTS = [
        'text', 'multiline_text', 'integer', 'decimal', 'date', 'date_time',
        'boolean', 'single_select', 'multi_select', 'measurement',
        'identifier_scanner', 'location', 'subject_reference', 'repeatable_group', 'photo',
    ];

    /** Components allowed inside one entry of a repeatable group. */
    public const ITEM_COMPONENTS = [
        'text', 'multiline_text', 'integer', 'decimal', 'date', 'boolean', 'single_select', 'measurement',
    ];

    public const MEASUREMENT_UNITS = ['kg', 'g', 'l', 'ml', 'cm', 'm', 'celsius', 'percent', 'count'];

    public const VALUE_TYPES = [
        'string', 'integer', 'decimal', 'boolean', 'date', 'date_time',
        'array', 'object', 'uuid', 'measurement', 'file',
    ];

    public const RESERVED_KEYS = [
        'id', 'uuid', 'farmUuid', 'farm_uuid', 'speciesUuid', 'species_uuid',
        'blueprintUuid', 'blueprint_uuid', 'blueprintVersion', 'blueprint_version',
        'createdAt', 'created_at', 'updatedAt', 'updated_at', 'deletedAt',
        'deleted_at', 'isDeleted', 'revision', 'createdByUuid', 'updatedByUuid',
        'deletedByUuid', 'restoredByUuid', 'syncAction', 'synced',
        'is_deleted', 'baseRevision', 'base_revision', 'created_by_uuid',
        'updated_by_uuid', 'deleted_by_uuid', 'restored_by_uuid',
        'restoredAt', 'restored_at', 'delete_reason', 'sync_action',
        'idempotencyUuid', 'idempotency_uuid', 'sourceDeviceUuid', 'source_device_uuid',
    ];

    public function validateDefinition(array $schema): array
    {
        $errors = [];
        $sections = $schema['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return [['code' => 'FORM_SECTIONS_REQUIRED', 'path' => 'sections']];
        }

        $keys = [];
        $uuids = [];
        $dependencies = [];
        $businessFields = 0;

        foreach ($sections as $sectionIndex => $section) {
            $fields = is_array($section) ? ($section['fields'] ?? null) : null;
            if (! is_array($fields)) {
                $errors[] = ['code' => 'SECTION_FIELDS_REQUIRED', 'path' => "sections.$sectionIndex.fields"];

                continue;
            }

            foreach ($fields as $fieldIndex => $field) {
                $path = "sections.$sectionIndex.fields.$fieldIndex";
                if (! is_array($field)) {
                    $errors[] = ['code' => 'FIELD_INVALID', 'path' => $path];

                    continue;
                }

                $key = $field['key'] ?? null;
                if (isset($field['core_binding']) && ! in_array($field['core_binding'], ['name', 'breedName', 'gender', 'sexCode', 'identificationNumber', 'dateOfBirth', 'dateFirstEnteredToFarm', 'weightAsOnRegistration', 'stageId', 'motherUuid', 'fatherUuid', 'primaryColor', 'secondaryColor', 'isIdentified', 'newWeight', 'oldWeight', 'vaccineUuid', 'diseaseId', 'remarks', 'eventDate', 'quantity'], true)) {
                    $errors[] = ['code' => 'FIELD_CORE_BINDING_UNSUPPORTED', 'path' => "$path.core_binding"];
                }
                $fieldUuid = $field['field_uuid'] ?? null;
                $component = $field['component'] ?? null;
                $valueType = $field['value_type'] ?? null;
                $dataOwner = $field['data_owner'] ?? 'user_input';

                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                    $errors[] = ['code' => 'FIELD_KEY_INVALID', 'path' => "$path.key"];
                } elseif (in_array($key, self::RESERVED_KEYS, true)) {
                    $errors[] = ['code' => 'FIELD_KEY_RESERVED', 'path' => "$path.key"];
                } elseif (isset($keys[$key])) {
                    $errors[] = ['code' => 'FIELD_KEY_DUPLICATE', 'path' => "$path.key"];
                } else {
                    $keys[$key] = $path;
                }

                if (! is_string($fieldUuid) || ! preg_match('/^[0-9a-f-]{36}$/i', $fieldUuid)) {
                    $errors[] = ['code' => 'FIELD_UUID_INVALID', 'path' => "$path.field_uuid"];
                } elseif (isset($uuids[$fieldUuid])) {
                    $errors[] = ['code' => 'FIELD_UUID_DUPLICATE', 'path' => "$path.field_uuid"];
                } else {
                    $uuids[$fieldUuid] = $path;
                }

                if (! in_array($component, self::COMPONENTS, true)) {
                    $errors[] = ['code' => 'FIELD_COMPONENT_UNSUPPORTED', 'path' => "$path.component"];
                } elseif (! in_array($component, self::MOBILE_COMPONENTS, true)) {
                    $errors[] = ['code' => 'FIELD_COMPONENT_NOT_AVAILABLE_ON_MOBILE', 'path' => "$path.component"];
                }
                if ($component === 'measurement' && ! in_array($field['unit'] ?? null, self::MEASUREMENT_UNITS, true)) {
                    $errors[] = ['code' => 'MEASUREMENT_UNIT_REQUIRED', 'path' => "$path.unit"];
                }
                if (isset($field['role']) && (! is_string($field['role']) || ! preg_match('/^[a-z][a-z0-9_]*$/', $field['role']))) {
                    $errors[] = ['code' => 'FIELD_ROLE_INVALID', 'path' => "$path.role"];
                }
                if ($component === 'repeatable_group') {
                    array_push($errors, ...$this->validateItemFields($field['item_fields'] ?? null, "$path.item_fields"));
                }
                if ($component === 'subject_reference') {
                    $reference = $field['reference'] ?? [];
                    if (! is_array($reference) || array_diff(array_keys($reference), ['same_species', 'sex_codes', 'multiple', 'unit_types']) !== []) {
                        $errors[] = ['code' => 'SUBJECT_REFERENCE_INVALID', 'path' => "$path.reference"];
                    }
                }
                if (in_array($component, ['single_select', 'multi_select'], true) && isset($field['lookup_code'])) {
                    $lookup = LookupSet::query()->where('code', $field['lookup_code'])->where('status', 'published')->latest('version')->first();
                    if (! $lookup) {
                        $errors[] = ['code' => 'LOOKUP_NOT_PUBLISHED', 'path' => "$path.lookup_code"];
                    }
                    if (isset($field['lookup_parent_field']) && (! is_string($field['lookup_parent_field']) || $field['lookup_parent_field'] === $key)) {
                        $errors[] = ['code' => 'LOOKUP_PARENT_FIELD_INVALID', 'path' => "$path.lookup_parent_field"];
                    }
                }
                if (! in_array($valueType, self::VALUE_TYPES, true)) {
                    $errors[] = ['code' => 'FIELD_VALUE_TYPE_UNSUPPORTED', 'path' => "$path.value_type"];
                }
                if (! in_array($dataOwner, ['user_input', 'server_generated', 'derived'], true)) {
                    $errors[] = ['code' => 'FIELD_DATA_OWNER_INVALID', 'path' => "$path.data_owner"];
                }
                if ($dataOwner !== 'user_input' && ($field['editable'] ?? false)) {
                    $errors[] = ['code' => 'SERVER_FIELD_EDITABLE', 'path' => "$path.editable"];
                }
                if (! filled($field['label'] ?? null) && empty($field['translations'])) {
                    $errors[] = ['code' => 'FIELD_LABEL_REQUIRED', 'path' => "$path.label"];
                }

                $required = $field['required'] ?? 'optional';
                $nullable = (bool) ($field['nullable'] ?? true);
                if ($required === 'always' && $nullable) {
                    $errors[] = ['code' => 'REQUIRED_FIELD_NULLABLE', 'path' => $path];
                }

                $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
                if (isset($validation['allowed_values']) && ! is_array($validation['allowed_values'])) {
                    $errors[] = ['code' => 'VALIDATION_ALLOWED_VALUES_INVALID', 'path' => "$path.validation.allowed_values"];
                }
                if (isset($validation['min'], $validation['max']) && $validation['min'] > $validation['max']) {
                    $errors[] = ['code' => 'VALIDATION_MIN_GREATER_THAN_MAX', 'path' => "$path.validation"];
                }
                if (isset($validation['min_length'], $validation['max_length']) && $validation['min_length'] > $validation['max_length']) {
                    $errors[] = ['code' => 'VALIDATION_MIN_LENGTH_GREATER_THAN_MAX', 'path' => "$path.validation"];
                }

                $dependencies[$key] = $this->referencedFields($field);
                if ($dataOwner === 'user_input') {
                    $businessFields++;
                }
            }
        }

        foreach ($dependencies as $key => $references) {
            foreach ($references as $reference) {
                if (! isset($keys[$reference])) {
                    $errors[] = ['code' => 'RULE_FIELD_MISSING', 'path' => $keys[$key] ?? 'sections', 'reference' => $reference];
                }
            }
        }

        if ($this->hasDependencyCycle($dependencies)) {
            $errors[] = ['code' => 'FIELD_DEPENDENCY_CYCLE', 'path' => 'sections'];
        }
        if ($businessFields === 0 && ! ($schema['allows_system_only_form'] ?? false)) {
            $errors[] = ['code' => 'BUSINESS_FIELD_REQUIRED', 'path' => 'sections'];
        }

        return $errors;
    }

    public function validatePayload(array $schema, array $payload): array
    {
        $errors = [];
        $fields = $this->flattenFields($schema);
        $allowed = array_keys($fields);
        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowed, true) || in_array($key, self::RESERVED_KEYS, true)) {
                $errors[$key][] = 'UNEXPECTED_OR_SYSTEM_FIELD';
            }
        }

        foreach ($fields as $key => $field) {
            if (($field['data_owner'] ?? 'user_input') !== 'user_input') {
                continue;
            }
            $value = $payload[$key] ?? null;
            if (($field['required'] ?? 'optional') === 'always' && ($value === null || $value === '')) {
                $errors[$key][] = 'REQUIRED';

                continue;
            }
            if ($value === null) {
                continue;
            }
            if (($field['required'] ?? 'optional') === 'always' && is_array($value) && $value === []) {
                $errors[$key][] = 'REQUIRED';

                continue;
            }
            if (! $this->matchesComponentType($field['component'] ?? null, $value, $field)) {
                $errors[$key][] = 'INVALID_TYPE';

                continue;
            }
            if (($field['component'] ?? null) === 'repeatable_group') {
                foreach ($this->validateItems($field, $value) as $path => $codes) {
                    $errors["$key.$path"] = $codes;
                }
            }
            if (($field['component'] ?? null) === 'photo' && ! $this->fileExists($value)) {
                $errors[$key][] = 'FILE_NOT_FOUND';
            }

            $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
            if (isset($validation['min_items']) && is_array($value) && count($value) < $validation['min_items']) {
                $errors[$key][] = 'MINIMUM_ITEMS';
            }
            if (isset($validation['max_items']) && is_array($value) && count($value) > $validation['max_items']) {
                $errors[$key][] = 'MAXIMUM_ITEMS';
            }
            if (isset($validation['min']) && is_numeric($value) && $value < $validation['min']) {
                $errors[$key][] = 'MINIMUM';
            }
            if (isset($validation['max']) && is_numeric($value) && $value > $validation['max']) {
                $errors[$key][] = 'MAXIMUM';
            }
            if (isset($validation['allowed_values']) && is_array($validation['allowed_values'])) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (! in_array($item, $validation['allowed_values'], true)) {
                        $errors[$key][] = 'NOT_ALLOWED';
                        break;
                    }
                }
            }
            if (in_array($field['component'] ?? null, ['single_select', 'multi_select'], true) && is_string($field['lookup_code'] ?? null)) {
                $this->validateLookupSelection($field, $value, $payload, $errors, $key);
            }
            if (is_string($value) && isset($validation['min_length']) && mb_strlen($value) < $validation['min_length']) {
                $errors[$key][] = 'MINIMUM_LENGTH';
            }
            if (is_string($value) && isset($validation['max_length']) && mb_strlen($value) > $validation['max_length']) {
                $errors[$key][] = 'MAXIMUM_LENGTH';
            }
        }

        return $errors;
    }

    public function assertDefinition(array $schema): void
    {
        if (($errors = $this->validateDefinition($schema)) !== []) {
            throw ValidationException::withMessages(['schema' => $errors]);
        }
    }

    public function assertPayload(array $schema, array $payload): void
    {
        if (($errors = $this->validatePayload($schema, $payload)) !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Must stay identical to the wire shapes produced by Flutter's FieldWidgetRegistry and DynamicFormValidator. */
    private function matchesComponentType(?string $component, mixed $value, array $field = []): bool
    {
        return match ($component) {
            'subject_reference' => ($field['reference']['multiple'] ?? false)
                ? is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, fn ($item) => ! is_string($item) || $item === '' || mb_strlen($item) > 100) === []
                : is_string($value) && $value !== '' && mb_strlen($value) <= 100,
            'repeatable_group' => is_array($value) && array_is_list($value) && array_filter($value, fn ($item) => ! is_array($item) || ($item !== [] && array_is_list($item))) === [],
            'photo' => is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) === 1,
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'decimal', 'measurement' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]),
            'date_time' => is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1
                && strtotime($value) !== false,
            'multi_select' => is_array($value) && array_is_list($value)
                && array_filter($value, fn ($item) => ! is_scalar($item)) === [],
            'single_select' => is_scalar($value),
            'location' => is_array($value)
                && isset($value['latitude'], $value['longitude'])
                && is_numeric($value['latitude']) && is_numeric($value['longitude'])
                && $value['latitude'] >= -90 && $value['latitude'] <= 90
                && $value['longitude'] >= -180 && $value['longitude'] <= 180
                && array_diff(array_keys($value), ['latitude', 'longitude', 'accuracy']) === [],
            'text', 'multiline_text', 'identifier_scanner' => is_string($value),
            default => true,
        };
    }

    private function validateItemFields(mixed $itemFields, string $path): array
    {
        if (! is_array($itemFields) || $itemFields === [] || ! array_is_list($itemFields)) {
            return [['code' => 'REPEATABLE_ITEM_FIELDS_REQUIRED', 'path' => $path]];
        }
        $errors = [];
        $keys = [];
        foreach ($itemFields as $index => $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]*$/', $key) || isset($keys[$key])) {
                $errors[] = ['code' => 'FIELD_KEY_INVALID', 'path' => "$path.$index.key"];
            }
            $keys[(string) $key] = true;
            if (! in_array($item['component'] ?? null, self::ITEM_COMPONENTS, true)) {
                $errors[] = ['code' => 'REPEATABLE_ITEM_COMPONENT_UNSUPPORTED', 'path' => "$path.$index.component"];
            }
            if (($item['component'] ?? null) === 'measurement' && ! in_array($item['unit'] ?? null, self::MEASUREMENT_UNITS, true)) {
                $errors[] = ['code' => 'MEASUREMENT_UNIT_REQUIRED', 'path' => "$path.$index.unit"];
            }
            if (! filled($item['label'] ?? null)) {
                $errors[] = ['code' => 'FIELD_LABEL_REQUIRED', 'path' => "$path.$index.label"];
            }
        }

        return $errors;
    }

    /** @return array<string, array<int, string>> errors keyed "index.sub_key" */
    private function validateItems(array $field, array $items): array
    {
        $errors = [];
        $subFields = collect($field['item_fields'] ?? [])->keyBy('key');
        foreach ($items as $index => $item) {
            foreach (array_keys($item) as $key) {
                if (! $subFields->has($key)) {
                    $errors["$index.$key"][] = 'UNEXPECTED_OR_SYSTEM_FIELD';
                }
            }
            foreach ($subFields as $key => $sub) {
                $value = $item[$key] ?? null;
                if ($value === null || $value === '') {
                    if (($sub['required'] ?? 'optional') === 'always') {
                        $errors["$index.$key"][] = 'REQUIRED';
                    }

                    continue;
                }
                if (! $this->matchesComponentType($sub['component'] ?? null, $value, $sub)) {
                    $errors["$index.$key"][] = 'INVALID_TYPE';

                    continue;
                }
                $allowed = $sub['validation']['allowed_values'] ?? null;
                if (is_array($allowed) && ! in_array($value, $allowed, true)) {
                    $errors["$index.$key"][] = 'NOT_ALLOWED';
                }
            }
        }

        return $errors;
    }

    private function fileExists(mixed $uuid): bool
    {
        return is_string($uuid) && \Illuminate\Support\Facades\Schema::hasTable('dynamic_files')
            && \Illuminate\Support\Facades\DB::table('dynamic_files')->where('uuid', $uuid)->exists();
    }

    private function flattenFields(array $schema): array
    {
        $result = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (is_array($field) && isset($field['key'])) {
                    $result[$field['key']] = $field;
                }
            }
        }

        return $result;
    }

    private function referencedFields(array $field): array
    {
        $references = [];
        if (is_string($field['lookup_parent_field'] ?? null)) {
            $references[] = $field['lookup_parent_field'];
        }
        $visibilityRule = $field['visibility_rule'] ?? [];
        $enabledRule = $field['enabled_rule'] ?? [];
        array_walk_recursive($visibilityRule, function ($value, $key) use (&$references): void {
            if ($key === 'field' && is_string($value)) {
                $references[] = $value;
            }
        });
        array_walk_recursive($enabledRule, function ($value, $key) use (&$references): void {
            if ($key === 'field' && is_string($value)) {
                $references[] = $value;
            }
        });

        return array_values(array_unique($references));
    }

    private function validateLookupSelection(array $field, mixed $value, array $payload, array &$errors, string $key): void
    {
        $setUuids = LookupSet::query()->where('code', $field['lookup_code'])->where('status', 'published')->pluck('uuid');
        if ($setUuids->isEmpty()) {
            $errors[$key][] = 'LOOKUP_UNAVAILABLE';
            return;
        }
        $values = is_array($value) ? $value : [$value];
        // All published versions remain valid for queued offline work. The
        // manifest offers only the newest version, while old selections never
        // become data loss merely because configuration advanced meanwhile.
        $options = LookupOption::query()->whereIn('lookup_set_uuid', $setUuids)->whereIn('code', $values)->with('parent')->get()->groupBy('code');
        foreach ($values as $selected) {
            $candidates = $options->get($selected, collect());
            if ($candidates->isEmpty()) {
                $errors[$key][] = 'NOT_ALLOWED';
                continue;
            }
            $parentField = $field['lookup_parent_field'] ?? null;
            if (is_string($parentField)) {
                $parentValue = $payload[$parentField] ?? null;
                if ($parentValue === null || ! $candidates->contains(fn ($option) => $option->parent?->code === $parentValue)) {
                    $errors[$key][] = 'LOOKUP_PARENT_MISMATCH';
                }
            }
        }
        if (isset($errors[$key])) {
            $errors[$key] = array_values(array_unique($errors[$key]));
        }
    }

    private function hasDependencyCycle(array $graph): bool
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if ($visit($next)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        foreach (array_keys($graph) as $node) {
            if ($visit((string) $node)) {
                return true;
            }
        }

        return false;
    }
}
