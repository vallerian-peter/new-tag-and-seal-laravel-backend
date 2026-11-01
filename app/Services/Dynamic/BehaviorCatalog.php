<?php

namespace App\Services\Dynamic;

use App\Models\BehaviorDefinition;
use Illuminate\Support\Collection;

/**
 * The behaviour contract: which inputs (roles) each behaviour reads, which form
 * components may feed them, which subject modes and species capabilities it needs.
 */
class BehaviorCatalog
{
    private ?Collection $definitions = null;

    public function all(): Collection
    {
        return $this->definitions ??= BehaviorDefinition::query()->where('status', 'active')->orderBy('sort_order')->get()->keyBy('code');
    }

    public function find(?string $code): ?BehaviorDefinition
    {
        return $code === null ? null : $this->all()->get($code);
    }

    /** @return array<string, mixed> role => submitted value, for roles the payload provides. */
    public function resolve(BehaviorDefinition $behavior, array $schema, array $payload): array
    {
        $values = [];
        foreach ($this->fieldsByRole($behavior, $schema) as $role => $field) {
            if (array_key_exists($field['key'], $payload)) {
                $values[$role] = $payload[$field['key']];
            }
        }

        return $values;
    }

    /** @return array<string, array> role => form field */
    public function fieldsByRole(BehaviorDefinition $behavior, array $schema): array
    {
        $fields = $this->fields($schema);
        $mapped = [];
        foreach ($behavior->roles_json as $role) {
            $field = collect($fields)->first(fn (array $field) => ($field['role'] ?? null) === $role['role'])
                ?? collect($fields)->first(fn (array $field) => ! isset($field['role']) && in_array($field['key'] ?? null, [$role['role'], ...($role['aliases'] ?? [])], true));
            if ($field) {
                $mapped[$role['role']] = $field;
            }
        }

        return $mapped;
    }

    /**
     * Publication check for one event. Returns error entries
     * ['code' => ..., 'path' => ...] so both apps can show the same messages.
     *
     * @param  array<int, string>  $subjectModes  modes the blueprint allows
     * @param  array<string, mixed>|null  $capabilities  species capabilities
     */
    public function validateEvent(?string $behaviorCode, array $schema, array $subjectModes, ?array $capabilities): array
    {
        $behavior = $this->find($behaviorCode);
        if (! $behavior) {
            return [['code' => 'BEHAVIOR_UNKNOWN', 'path' => 'behavior_code']];
        }
        $errors = [];
        if (array_intersect($behavior->subject_modes_json, $subjectModes) === []) {
            $errors[] = ['code' => 'BEHAVIOR_SUBJECT_MODE_UNSUPPORTED', 'path' => 'behavior_code'];
        }
        foreach ($behavior->requires_json ?? [] as $capability => $allowed) {
            $value = $capabilities[$capability] ?? null;
            if ($capabilities !== null && ! in_array($value, $allowed, true)) {
                $errors[] = ['code' => 'BEHAVIOR_CAPABILITY_MISSING', 'path' => "capabilities.$capability"];
            }
        }
        $roles = collect($behavior->roles_json)->keyBy('role');
        $mapped = $this->fieldsByRole($behavior, $schema);
        foreach ($roles as $code => $role) {
            $field = $mapped[$code] ?? null;
            if (! $field) {
                if ($role['required']) {
                    $errors[] = ['code' => 'BEHAVIOR_ROLE_MISSING', 'path' => "roles.$code"];
                }

                continue;
            }
            if (! in_array($field['component'] ?? null, $role['components'], true)) {
                $errors[] = ['code' => 'BEHAVIOR_ROLE_COMPONENT_MISMATCH', 'path' => "roles.$code"];
            }
            if ($role['required'] && ($field['required'] ?? 'optional') !== 'always') {
                $errors[] = ['code' => 'BEHAVIOR_ROLE_MUST_BE_REQUIRED', 'path' => "roles.$code"];
            }
        }
        $declared = collect($this->fields($schema))->pluck('role')->filter();
        foreach ($declared->countBy() as $code => $count) {
            if (! $roles->has($code)) {
                $errors[] = ['code' => 'BEHAVIOR_ROLE_UNKNOWN', 'path' => "roles.$code"];
            } elseif ($count > 1) {
                $errors[] = ['code' => 'BEHAVIOR_ROLE_DUPLICATE', 'path' => "roles.$code"];
            }
        }

        return $errors;
    }

    public function manifest(): array
    {
        return $this->all()->values()->map(fn (BehaviorDefinition $behavior) => [
            'code' => $behavior->code,
            'family' => $behavior->family,
            'name' => $behavior->translations_json,
            'subjectModes' => $behavior->subject_modes_json,
            'requires' => $behavior->requires_json,
            'roles' => $behavior->roles_json,
            'effects' => $behavior->effects_json,
            'reversible' => $behavior->reversible,
        ])->all();
    }

    private function fields(array $schema): array
    {
        return collect($schema['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? [])->filter(fn ($field) => is_array($field))->values()->all();
    }
}
