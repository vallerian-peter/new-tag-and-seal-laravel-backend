<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Behaviour catalogue (the single contract shared by the API, the Portal and the
 * mobile manifest), species capabilities, the server change feed, the current-state
 * summary tables and the per-family authority flags.
 *
 * The catalogue lives here, not in a seeder, because the Portal's tests build their
 * schema by running these migration files directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('family', 50)->index();
            $table->string('name');
            $table->json('translations_json');
            $table->json('subject_modes_json');
            $table->json('roles_json');
            $table->json('requires_json')->nullable();
            $table->json('effects_json');
            $table->boolean('reversible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        $now = now();
        foreach (self::catalog() as $index => $behavior) {
            DB::table('behavior_definitions')->insert([
                'code' => $behavior['code'],
                'family' => $behavior['family'],
                'name' => $behavior['name']['en'],
                'translations_json' => json_encode($behavior['name'], JSON_UNESCAPED_UNICODE),
                'subject_modes_json' => json_encode($behavior['modes']),
                'roles_json' => json_encode($behavior['roles'], JSON_UNESCAPED_UNICODE),
                'requires_json' => isset($behavior['requires']) ? json_encode($behavior['requires']) : null,
                'effects_json' => json_encode($behavior['effects']),
                'reversible' => $behavior['reversible'],
                'sort_order' => $index,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('animal_species', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_livestock_type_id')->nullable()->index();
            $table->json('capabilities_json')->nullable();
        });
        $legacyTypeNames = ['cattle' => 'Cattle', 'pig' => 'Swine', 'goat' => 'Goat', 'sheep' => 'Sheep or Lamb', 'chicken' => 'Chicken'];
        foreach (DB::table('animal_species')->get() as $species) {
            $typeId = Schema::hasTable('livestock_types') && isset($legacyTypeNames[$species->code])
                ? DB::table('livestock_types')->where('name', $legacyTypeNames[$species->code])->value('id')
                : null;
            DB::table('animal_species')->where('id', $species->id)->update([
                'legacy_livestock_type_id' => $typeId,
                'capabilities_json' => json_encode(self::defaultCapabilities($species->code, $species->default_subject_mode)),
            ]);
        }

        Schema::table('event_records', function (Blueprint $table): void {
            $table->string('behavior_code', 100)->nullable()->index();
        });
        foreach (DB::table('event_definitions')->select('uuid', 'behavior_code')->get() as $definition) {
            DB::table('event_records')->where('event_definition_uuid', $definition->uuid)->update(['behavior_code' => $definition->behavior_code]);
        }

        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('farm_uuid', 36);
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 100);
            $table->string('action', 20);
            $table->unsignedBigInteger('revision')->default(1);
            $table->json('data_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['farm_uuid', 'id']);
            $table->index(['entity_type', 'entity_uuid']);
        });
        $this->backfillChanges();

        Schema::create('subject_reproduction_state', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_uuid', 100)->unique();
            $table->string('farm_uuid', 36)->index();
            $table->unsignedInteger('births_count')->default(0);
            $table->unsignedBigInteger('total_born_alive')->default(0);
            $table->unsignedBigInteger('total_born_dead')->default(0);
            $table->timestamp('last_birth_at')->nullable();
            $table->unsignedInteger('last_born_alive')->nullable();
            $table->unsignedInteger('last_born_dead')->nullable();
            $table->string('last_event_uuid', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('subject_production_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_uuid', 100)->index();
            $table->string('farm_uuid', 36)->index();
            $table->date('production_date');
            $table->string('product_code', 100);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('damaged_quantity', 14, 3)->default(0);
            $table->string('unit', 30)->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamps();
            $table->unique(['subject_uuid', 'production_date', 'product_code'], 'subject_production_daily_unique');
        });

        Schema::create('dynamic_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('farm_uuid', 36)->nullable()->index();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('content_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->uuid('created_by_uuid')->nullable();
            $table->timestamps();
        });

        foreach (self::FAMILIES as $family) {
            DB::table('platform_feature_flags')->insertOrIgnore([
                'key' => "family.$family.authority",
                'enabled' => true,
                'value_json' => json_encode(['authority' => 'legacy']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_feature_flags')->where('key', 'like', 'family.%.authority')->delete();
        Schema::dropIfExists('dynamic_files');
        Schema::dropIfExists('subject_production_daily');
        Schema::dropIfExists('subject_reproduction_state');
        Schema::dropIfExists('sync_changes');
        Schema::table('event_records', fn (Blueprint $table) => $table->dropColumn('behavior_code'));
        Schema::table('animal_species', fn (Blueprint $table) => $table->dropColumn(['legacy_livestock_type_id', 'capabilities_json']));
        Schema::dropIfExists('behavior_definitions');
    }

    /** Families that still have legacy tables and move to the ledger one at a time. */
    private const FAMILIES = ['births', 'health', 'production', 'breeding', 'growth', 'movement'];

    private function backfillChanges(): void
    {
        $rows = [];
        foreach (DB::table('animal_subjects')->orderBy('id')->get() as $subject) {
            $rows[] = [
                'farm_uuid' => $subject->farm_uuid,
                'entity_type' => 'animal_subject',
                'entity_uuid' => $subject->uuid,
                'action' => $subject->deleted_at ? 'delete' : 'upsert',
                'revision' => $subject->revision,
                'data_json' => json_encode($subject),
                'created_at' => now(),
            ];
        }
        foreach (DB::table('event_records')->orderBy('id')->get() as $record) {
            $rows[] = [
                'farm_uuid' => $record->farm_uuid,
                'entity_type' => 'event_record',
                'entity_uuid' => $record->uuid,
                'action' => 'upsert',
                'revision' => $record->revision,
                'data_json' => json_encode($record),
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('sync_changes')->insert($chunk);
        }
    }

    private static function defaultCapabilities(string $code, ?string $defaultMode): array
    {
        $modes = match ($defaultMode) {
            'group' => ['group'],
            'individual' => ['individual'],
            default => ['individual', 'group'],
        };

        return match ($code) {
            'cattle', 'goat', 'sheep' => ['subjectModes' => $modes, 'reproduction' => 'live_birth', 'products' => ['milk', 'meat']],
            'pig', 'rabbit' => ['subjectModes' => $modes, 'reproduction' => 'live_birth', 'products' => ['meat']],
            'chicken' => ['subjectModes' => $modes, 'reproduction' => 'egg_laying', 'products' => ['eggs', 'meat']],
            'fish' => ['subjectModes' => $modes, 'reproduction' => 'spawning', 'products' => ['fish']],
            'bee' => ['subjectModes' => $modes, 'reproduction' => 'colony', 'products' => ['honey', 'wax']],
            default => ['subjectModes' => $modes, 'reproduction' => 'none', 'products' => []],
        };
    }

    /**
     * Each role names an input a behaviour reads. A form field feeds a role by
     * declaring `role`; `aliases` keep forms published before roles existed working.
     */
    private static function catalog(): array
    {
        $role = fn (string $code, array $label, array $components, bool $required, array $aliases = []) => [
            'role' => $code, 'label' => $label, 'components' => $components, 'required' => $required, 'aliases' => $aliases,
        ];
        $both = ['individual', 'group'];

        return [
            ['code' => 'record_only', 'family' => 'general', 'name' => ['en' => 'Record only', 'sw' => 'Rekodi tu'], 'modes' => $both, 'roles' => [], 'effects' => [], 'reversible' => true],
            ['code' => 'record_treatment', 'family' => 'health', 'name' => ['en' => 'Health treatment', 'sw' => 'Matibabu'], 'modes' => $both, 'roles' => [
                $role('product', ['en' => 'Medicine or vaccine', 'sw' => 'Dawa au chanjo'], ['single_select', 'text'], false, ['vaccine', 'medicine']),
                $role('dose', ['en' => 'Dose', 'sw' => 'Kipimo'], ['decimal', 'measurement', 'integer'], false),
            ], 'effects' => ['subject.last_treated_at'], 'reversible' => true],
            ['code' => 'update_weight', 'family' => 'growth', 'name' => ['en' => 'Weight update', 'sw' => 'Sasisha uzito'], 'modes' => $both, 'roles' => [
                $role('weight', ['en' => 'Weight', 'sw' => 'Uzito'], ['decimal', 'measurement', 'integer'], true),
            ], 'effects' => ['subject.current_weight'], 'reversible' => true],
            ['code' => 'record_biomass_sample', 'family' => 'growth', 'name' => ['en' => 'Biomass sample', 'sw' => 'Sampuli ya uzito wa kundi'], 'modes' => ['group'], 'roles' => [
                $role('biomass', ['en' => 'Biomass', 'sw' => 'Uzito wa kundi'], ['decimal', 'measurement'], true),
            ], 'effects' => ['subject.biomass'], 'reversible' => false],
            ['code' => 'record_birth', 'family' => 'births', 'name' => ['en' => 'Birth', 'sw' => 'Kuzaa'], 'modes' => $both, 'requires' => ['reproduction' => ['live_birth']], 'roles' => [
                $role('born_alive', ['en' => 'Born alive', 'sw' => 'Waliozaliwa hai'], ['integer'], true, ['alive_count', 'aliveCount']),
                $role('born_dead', ['en' => 'Born dead', 'sw' => 'Waliozaliwa wafu'], ['integer'], false, ['dead_count', 'deadCount']),
                $role('birth_type', ['en' => 'Birth type', 'sw' => 'Aina ya uzazi'], ['single_select'], false),
                $role('offspring', ['en' => 'Offspring', 'sw' => 'Watoto'], ['repeatable_group'], false),
            ], 'effects' => ['reproduction_state', 'group.quantity', 'offspring.create'], 'reversible' => true],
            ['code' => 'create_offspring', 'family' => 'births', 'name' => ['en' => 'Register offspring', 'sw' => 'Sajili watoto'], 'modes' => ['individual'], 'roles' => [
                $role('offspring', ['en' => 'Offspring', 'sw' => 'Watoto'], ['repeatable_group'], true),
            ], 'effects' => ['offspring.create'], 'reversible' => false],
            ['code' => 'record_egg_or_clutch', 'family' => 'production', 'name' => ['en' => 'Egg collection', 'sw' => 'Ukusanyaji wa mayai'], 'modes' => $both, 'requires' => ['reproduction' => ['egg_laying']], 'roles' => [
                $role('egg_count', ['en' => 'Eggs collected', 'sw' => 'Mayai yaliyokusanywa'], ['integer'], true, ['total_eggs']),
                $role('damaged_count', ['en' => 'Damaged eggs', 'sw' => 'Mayai yaliyoharibika'], ['integer'], false, ['broken_eggs']),
            ], 'effects' => ['production_daily'], 'reversible' => true],
            ['code' => 'record_hatching', 'family' => 'births', 'name' => ['en' => 'Hatching', 'sw' => 'Kuangua'], 'modes' => $both, 'requires' => ['reproduction' => ['egg_laying']], 'roles' => [
                $role('hatched_count', ['en' => 'Hatched', 'sw' => 'Walioanguliwa'], ['integer'], false),
            ], 'effects' => ['group.quantity'], 'reversible' => true],
            ['code' => 'record_spawning', 'family' => 'births', 'name' => ['en' => 'Spawning', 'sw' => 'Kuzaa samaki'], 'modes' => $both, 'requires' => ['reproduction' => ['spawning']], 'roles' => [], 'effects' => [], 'reversible' => true],
            ['code' => 'record_production_yield', 'family' => 'production', 'name' => ['en' => 'Production yield', 'sw' => 'Mavuno ya uzalishaji'], 'modes' => $both, 'roles' => [
                $role('quantity', ['en' => 'Quantity', 'sw' => 'Kiasi'], ['decimal', 'integer', 'measurement'], true),
                $role('product', ['en' => 'Product', 'sw' => 'Bidhaa'], ['single_select', 'text'], false),
                $role('unit', ['en' => 'Unit', 'sw' => 'Kipimo'], ['single_select', 'text'], false),
            ], 'effects' => ['production_daily'], 'reversible' => true],
            ['code' => 'record_mortality', 'family' => 'population', 'name' => ['en' => 'Mortality', 'sw' => 'Vifo'], 'modes' => ['group'], 'roles' => [
                $role('count', ['en' => 'Number lost', 'sw' => 'Idadi iliyokufa'], ['integer'], true, ['dead_count', 'mortality_count']),
                $role('cause', ['en' => 'Cause', 'sw' => 'Sababu'], ['single_select', 'text'], false),
            ], 'effects' => ['group.quantity'], 'reversible' => true],
            ['code' => 'adjust_group_quantity', 'family' => 'population', 'name' => ['en' => 'Adjust head count', 'sw' => 'Rekebisha idadi'], 'modes' => ['group'], 'roles' => [
                $role('quantity', ['en' => 'Quantity', 'sw' => 'Idadi'], ['integer'], true),
            ], 'effects' => ['group.quantity'], 'reversible' => false],
            ['code' => 'record_group_census', 'family' => 'population', 'name' => ['en' => 'Head count', 'sw' => 'Hesabu ya kundi'], 'modes' => ['group'], 'roles' => [
                $role('quantity', ['en' => 'Quantity', 'sw' => 'Idadi'], ['integer'], true),
            ], 'effects' => ['group.quantity'], 'reversible' => false],
            ['code' => 'split_group', 'family' => 'population', 'name' => ['en' => 'Split group', 'sw' => 'Gawa kundi'], 'modes' => ['group'], 'roles' => [
                $role('split_quantity', ['en' => 'Quantity to move', 'sw' => 'Idadi ya kuhamisha'], ['integer'], true),
                $role('new_group_name', ['en' => 'New group name', 'sw' => 'Jina la kundi jipya'], ['text'], false),
            ], 'effects' => ['group.quantity', 'group.create'], 'reversible' => false],
            ['code' => 'merge_groups', 'family' => 'population', 'name' => ['en' => 'Merge groups', 'sw' => 'Unganisha makundi'], 'modes' => ['group'], 'roles' => [
                $role('source_group_uuids', ['en' => 'Groups to merge', 'sw' => 'Makundi ya kuunganisha'], ['subject_reference', 'multi_select'], true),
            ], 'effects' => ['group.quantity', 'group.merge'], 'reversible' => false],
            ['code' => 'transition_stage', 'family' => 'lifecycle', 'name' => ['en' => 'Stage change', 'sw' => 'Badilisha hatua'], 'modes' => $both, 'roles' => [
                $role('stage_code', ['en' => 'New stage', 'sw' => 'Hatua mpya'], ['single_select', 'text'], true),
            ], 'effects' => ['subject.current_stage_code'], 'reversible' => false],
            ['code' => 'change_lifecycle_status', 'family' => 'lifecycle', 'name' => ['en' => 'Status change', 'sw' => 'Badilisha hali'], 'modes' => $both, 'roles' => [
                $role('lifecycle_status', ['en' => 'New status', 'sw' => 'Hali mpya'], ['single_select'], true),
            ], 'effects' => ['subject.lifecycle_status'], 'reversible' => false],
            ['code' => 'transfer_subject', 'family' => 'movement', 'name' => ['en' => 'Transfer', 'sw' => 'Hamisha'], 'modes' => $both, 'roles' => [
                $role('destination_farm_uuid', ['en' => 'Destination farm', 'sw' => 'Shamba unakohamia'], ['single_select', 'text'], true),
            ], 'effects' => ['subject.farm_uuid'], 'reversible' => false],
            ['code' => 'assign_identifier', 'family' => 'identity', 'name' => ['en' => 'Assign identifier', 'sw' => 'Weka kitambulisho'], 'modes' => $both, 'roles' => [
                $role('identifier_type_code', ['en' => 'Identifier type', 'sw' => 'Aina ya kitambulisho'], ['single_select', 'text'], true),
                $role('identifier_value', ['en' => 'Identifier', 'sw' => 'Kitambulisho'], ['identifier_scanner', 'text'], true),
            ], 'effects' => ['subject.identifiers'], 'reversible' => false],
            ['code' => 'retire_identifier', 'family' => 'identity', 'name' => ['en' => 'Retire identifier', 'sw' => 'Ondoa kitambulisho'], 'modes' => $both, 'roles' => [
                $role('identifier_uuid', ['en' => 'Identifier', 'sw' => 'Kitambulisho'], ['single_select', 'text'], true),
            ], 'effects' => ['subject.identifiers'], 'reversible' => false],
            ['code' => 'record_environment_measurement', 'family' => 'general', 'name' => ['en' => 'Environment reading', 'sw' => 'Kipimo cha mazingira'], 'modes' => $both, 'roles' => [], 'effects' => [], 'reversible' => true],
            ['code' => 'schedule_followup', 'family' => 'general', 'name' => ['en' => 'Follow-up', 'sw' => 'Ufuatiliaji'], 'modes' => $both, 'roles' => [
                $role('follow_up_date', ['en' => 'Follow-up date', 'sw' => 'Tarehe ya ufuatiliaji'], ['date', 'date_time'], false),
            ], 'effects' => [], 'reversible' => true],
        ];
    }
};
