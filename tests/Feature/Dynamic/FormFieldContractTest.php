<?php

namespace Tests\Feature\Dynamic;

use App\Services\Dynamic\FormSchemaValidator;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormFieldContractTest extends TestCase
{
    public function test_payload_values_must_match_their_component_wire_shape(): void
    {
        $validator = new FormSchemaValidator;
        $schema = $this->schema([
            $this->field('count', 'integer'),
            $this->field('weight', 'measurement', ['unit' => 'kg']),
            $this->field('vaccinated', 'boolean'),
            $this->field('seen_on', 'date'),
            $this->field('seen_at', 'date_time'),
            $this->field('signs', 'multi_select', ['validation' => ['allowed_values' => ['cough', 'fever']]]),
            $this->field('purpose', 'single_select', ['validation' => ['allowed_values' => ['milk', 'meat']]]),
            $this->field('pasture', 'location'),
            $this->field('tag', 'identifier_scanner'),
        ]);

        $this->assertSame([], $validator->validatePayload($schema, [
            'count' => 4,
            'weight' => 45.5,
            'vaccinated' => false,
            'seen_on' => '2026-10-28',
            'seen_at' => '2026-10-28T08:15:00.000Z',
            'signs' => ['cough', 'fever'],
            'purpose' => 'milk',
            'pasture' => ['latitude' => -6.8, 'longitude' => 39.28],
            'tag' => 'TZ-0001',
        ]));

        $errors = $validator->validatePayload($schema, [
            'count' => 4.5,
            'weight' => '45',
            'vaccinated' => 'no',
            'seen_on' => '2026-02-30',
            'seen_at' => 'yesterday',
            'signs' => ['cough', 'unknown'],
            'purpose' => 'eggs',
            'pasture' => ['latitude' => 120, 'longitude' => 39],
            'tag' => 42,
        ]);
        $this->assertSame(['INVALID_TYPE'], $errors['count']);
        $this->assertSame(['INVALID_TYPE'], $errors['weight']);
        $this->assertSame(['INVALID_TYPE'], $errors['vaccinated']);
        $this->assertSame(['INVALID_TYPE'], $errors['seen_on']);
        $this->assertSame(['INVALID_TYPE'], $errors['seen_at']);
        $this->assertSame(['NOT_ALLOWED'], $errors['signs']);
        $this->assertSame(['NOT_ALLOWED'], $errors['purpose']);
        $this->assertSame(['INVALID_TYPE'], $errors['pasture']);
        $this->assertSame(['INVALID_TYPE'], $errors['tag']);
    }

    public function test_required_multi_select_rejects_an_empty_selection(): void
    {
        $schema = $this->schema([$this->field('signs', 'multi_select', [
            'required' => 'always', 'nullable' => false,
            'validation' => ['allowed_values' => ['cough']],
        ])]);

        $this->assertSame(['REQUIRED'], (new FormSchemaValidator)->validatePayload($schema, ['signs' => []])['signs']);
    }

    public function test_publication_blocks_components_the_mobile_app_cannot_render(): void
    {
        $codes = collect((new FormSchemaValidator)->validateDefinition($this->schema([
            $this->field('attachment', 'file'),
            $this->field('litter', 'repeatable_group'),
            $this->field('weight', 'measurement'),
        ])))->pluck('code')->all();

        $this->assertSame([
            'FIELD_COMPONENT_NOT_AVAILABLE_ON_MOBILE',
            'REPEATABLE_ITEM_FIELDS_REQUIRED',
            'MEASUREMENT_UNIT_REQUIRED',
        ], $codes);
    }

    public function test_reference_group_and_photo_wire_shapes(): void
    {
        $validator = new FormSchemaValidator;
        $litter = $this->field('litter', 'repeatable_group', ['value_type' => 'array', 'item_fields' => [
            ['key' => 'sex_code', 'label' => ['en' => 'Sex'], 'component' => 'single_select', 'required' => 'always', 'validation' => ['allowed_values' => ['male', 'female']]],
            ['key' => 'weight', 'label' => ['en' => 'Weight'], 'component' => 'measurement', 'unit' => 'kg'],
        ], 'validation' => ['max_items' => 2]]);
        $schema = $this->schema([
            $this->field('mother', 'subject_reference'),
            $this->field('sources', 'subject_reference', ['reference' => ['multiple' => true]]),
            $litter,
            $this->field('proof', 'photo'),
        ]);

        $this->assertSame([], $validator->validateDefinition($schema));
        $this->assertSame([], $validator->validatePayload($schema, [
            'mother' => '1762684491467-186038508',
            'sources' => ['a', 'b'],
            'litter' => [['sex_code' => 'female', 'weight' => 1.2], ['sex_code' => 'male']],
        ]));
        $errors = $validator->validatePayload($schema, [
            'mother' => ['not-a-string'],
            'sources' => 'single',
            'litter' => [['sex_code' => 'other', 'colour' => 'red'], ['weight' => 'heavy'], ['sex_code' => 'male']],
            'proof' => (string) Str::uuid(),
        ]);
        $this->assertSame(['INVALID_TYPE'], $errors['mother']);
        $this->assertSame(['INVALID_TYPE'], $errors['sources']);
        $this->assertSame(['MAXIMUM_ITEMS'], $errors['litter']);
        $this->assertSame(['NOT_ALLOWED'], $errors['litter.0.sex_code']);
        $this->assertSame(['UNEXPECTED_OR_SYSTEM_FIELD'], $errors['litter.0.colour']);
        $this->assertSame(['REQUIRED'], $errors['litter.1.sex_code']);
        $this->assertSame(['INVALID_TYPE'], $errors['litter.1.weight']);
        $this->assertSame(['FILE_NOT_FOUND'], $errors['proof']);
    }

    private function schema(array $fields): array
    {
        return ['sections' => [['key' => 'main', 'fields' => $fields]]];
    }

    private function field(string $key, string $component, array $overrides = []): array
    {
        return array_replace([
            'field_uuid' => (string) Str::uuid(),
            'key' => $key,
            'label' => ['en' => $key],
            'component' => $component,
            'value_type' => 'string',
            'data_owner' => 'user_input',
            'required' => 'optional',
            'nullable' => true,
        ], $overrides);
    }
}
