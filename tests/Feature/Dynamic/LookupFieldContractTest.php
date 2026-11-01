<?php

namespace Tests\Feature\Dynamic;

use App\Models\LookupSet;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\FormSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LookupFieldContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_published_lookup_data_and_parent_relationships_are_compiled(): void
    {
        $regions = LookupSet::create(['code' => 'geo.regions', 'name' => 'Regions', 'version' => 1, 'status' => 'published']);
        $dar = $regions->options()->create(['code' => 'dar', 'label' => 'Dar es Salaam', 'status' => 'active']);
        $regions->options()->create(['code' => 'old_region', 'label' => 'Historic Region', 'status' => 'retired']);
        $districts = LookupSet::create(['code' => 'geo.districts', 'name' => 'Districts', 'version' => 1, 'status' => 'published', 'parent_lookup_set_uuid' => $regions->uuid]);
        $districts->options()->create(['code' => 'kinondoni', 'label' => 'Kinondoni', 'status' => 'active', 'parent_option_uuid' => $dar->uuid]);

        $lookups = collect(app(ConfigurationSyncService::class)->compile(7)['lookups'])->keyBy('code');
        $this->assertSame('geo.regions', $lookups['geo.districts']['parentLookupCode']);
        $this->assertSame('dar', $lookups['geo.districts']['options'][0]['parentValue']);
        $this->assertFalse(collect($lookups['geo.regions']['options'])->firstWhere('value', 'old_region')['selectable']);
    }

    public function test_backend_rejects_unknown_and_cross_parent_lookup_values(): void
    {
        $regions = LookupSet::create(['code' => 'geo.regions', 'name' => 'Regions', 'version' => 1, 'status' => 'published']);
        $dar = $regions->options()->create(['code' => 'dar', 'label' => 'Dar es Salaam', 'status' => 'active']);
        $arusha = $regions->options()->create(['code' => 'arusha', 'label' => 'Arusha', 'status' => 'active']);
        $districts = LookupSet::create(['code' => 'geo.districts', 'name' => 'Districts', 'version' => 1, 'status' => 'published', 'parent_lookup_set_uuid' => $regions->uuid]);
        $districts->options()->create(['code' => 'kinondoni', 'label' => 'Kinondoni', 'status' => 'active', 'parent_option_uuid' => $dar->uuid]);

        $schema = ['sections' => [['key' => 'location', 'fields' => [
            $this->field('region', 'geo.regions'),
            $this->field('district', 'geo.districts', 'region'),
        ]]]];
        $validator = app(FormSchemaValidator::class);
        $this->assertSame([], $validator->validateDefinition($schema));
        $this->assertSame([], $validator->validatePayload($schema, ['region' => 'dar', 'district' => 'kinondoni']));
        $this->assertSame(['NOT_ALLOWED'], $validator->validatePayload($schema, ['region' => 'dar', 'district' => 'missing'])['district']);
        $this->assertSame(['LOOKUP_PARENT_MISMATCH'], $validator->validatePayload($schema, ['region' => $arusha->code, 'district' => 'kinondoni'])['district']);
    }

    private function field(string $key, string $lookup, ?string $parent = null): array
    {
        return array_filter([
            'field_uuid' => (string) Str::uuid(), 'key' => $key, 'label' => ['en' => $key],
            'component' => 'single_select', 'value_type' => 'string', 'data_owner' => 'user_input',
            'required' => 'always', 'nullable' => false, 'lookup_code' => $lookup,
            'lookup_parent_field' => $parent,
        ], fn ($value) => $value !== null);
    }
}
