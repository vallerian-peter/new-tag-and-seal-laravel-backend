<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animal_species', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->json('translations_json')->nullable();
            $table->string('category_code', 100)->nullable();
            $table->string('default_subject_mode', 20)->default('individual');
            $table->string('group_display_name')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->uuid('created_by_uuid')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('form_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 150);
            $table->unsignedInteger('version');
            $table->string('purpose', 30);
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('system_envelope_version')->default(1);
            $table->json('schema_json');
            $table->string('checksum', 80)->nullable();
            $table->unsignedInteger('minimum_app_schema_version')->default(1);
            $table->uuid('created_by_uuid')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
        });

        Schema::create('animal_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('species_uuid')->index();
            $table->string('code', 150);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->uuid('registration_form_uuid');
            $table->unsignedInteger('registration_form_version');
            $table->json('settings_json');
            $table->json('compiled_manifest_json')->nullable();
            $table->string('checksum', 80)->nullable();
            $table->uuid('created_by_uuid')->nullable();
            $table->uuid('published_by_uuid')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['species_uuid', 'version']);
            $table->unique(['code', 'version']);
        });

        Schema::create('event_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('blueprint_uuid')->index();
            $table->unsignedInteger('blueprint_version');
            $table->string('code', 150);
            $table->string('name');
            $table->json('translations_json')->nullable();
            $table->uuid('form_uuid');
            $table->unsignedInteger('form_version');
            $table->string('behavior_code', 100);
            $table->json('eligibility_rule_json')->nullable();
            $table->json('repeatability_rule_json')->nullable();
            $table->json('result_rule_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->unique(['blueprint_uuid', 'blueprint_version', 'code']);
        });

        Schema::create('animal_subjects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('farm_uuid')->index();
            $table->uuid('species_uuid')->index();
            $table->uuid('blueprint_uuid')->index();
            $table->unsignedInteger('blueprint_version');
            $table->string('unit_type', 20)->index();
            $table->string('display_name')->nullable();
            $table->date('birth_or_start_date')->nullable();
            $table->string('sex_code', 30)->nullable();
            $table->unsignedBigInteger('quantity')->nullable();
            $table->decimal('biomass', 14, 3)->nullable();
            $table->string('quantity_unit', 50)->nullable();
            $table->unsignedBigInteger('male_count')->nullable();
            $table->unsignedBigInteger('female_count')->nullable();
            $table->unsignedBigInteger('unknown_sex_count')->nullable();
            $table->string('composition_status', 30)->nullable();
            $table->string('current_stage_code', 100)->nullable();
            $table->string('lifecycle_status', 50)->default('active')->index();
            $table->json('attributes_json');
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('created_by_uuid')->nullable();
            $table->uuid('updated_by_uuid')->nullable();
            $table->uuid('deleted_by_uuid')->nullable();
            $table->text('delete_reason')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->uuid('restored_by_uuid')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('identifier_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->json('translations_json')->nullable();
            $table->json('supported_subject_modes');
            $table->json('format_rule')->nullable();
            $table->string('normalization_rule', 50)->default('trim_uppercase');
            $table->string('uniqueness_scope', 20)->default('global');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('subject_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('subject_uuid')->index();
            $table->uuid('identifier_type_uuid')->index();
            $table->string('normalized_value', 255)->index();
            $table->string('display_value', 255);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('assigned_at');
            $table->timestamp('retired_at')->nullable();
            $table->string('issuer')->nullable();
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('deleted_by_uuid')->nullable();
            $table->text('delete_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['identifier_type_uuid', 'normalized_value', 'status'], 'subject_identifiers_active_unique');
        });

        Schema::create('subject_identifier_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('subject_uuid')->index();
            $table->uuid('identifier_uuid')->index();
            $table->string('action', 30);
            $table->json('payload_json')->nullable();
            $table->uuid('actor_uuid')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('event_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('farm_uuid')->index();
            $table->uuid('subject_uuid')->index();
            $table->uuid('event_definition_uuid')->index();
            $table->string('event_code', 150)->index();
            $table->unsignedInteger('event_definition_version');
            $table->uuid('form_uuid');
            $table->unsignedInteger('form_version');
            $table->timestamp('occurred_at');
            $table->json('payload_json');
            $table->string('record_status', 20)->default('submitted')->index();
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by_uuid')->nullable();
            $table->uuid('created_by_uuid')->nullable();
            $table->uuid('source_device_uuid')->nullable();
            $table->uuid('idempotency_uuid')->unique();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('configuration_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->unique();
            $table->string('schema_version', 30)->default('1');
            $table->string('checksum', 80);
            $table->json('manifest_json');
            $table->uuid('published_by_uuid')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();
        });

        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('entity_type', 100);
            $table->uuid('entity_uuid')->nullable();
            $table->string('error_code', 100);
            $table->json('client_payload_json');
            $table->json('server_metadata_json')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('user_platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_uuid')->index();
            $table->unsignedBigInteger('permission_id');
            $table->uuid('granted_by_uuid')->nullable();
            $table->timestamps();
            $table->unique(['user_uuid', 'permission_id']);
        });

        Schema::create('platform_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 150)->unique();
            $table->boolean('enabled')->default(false);
            $table->json('value_json')->nullable();
            $table->uuid('updated_by_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'platform_feature_flags',
            'user_platform_permissions',
            'platform_permissions',
            'sync_conflicts',
            'configuration_revisions',
            'event_records',
            'subject_identifier_events',
            'subject_identifiers',
            'identifier_types',
            'animal_subjects',
            'event_definitions',
            'animal_blueprints',
            'form_definitions',
            'animal_species',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
