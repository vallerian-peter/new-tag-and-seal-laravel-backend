<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_upgrade_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('release', 100);
            $table->string('environment', 50);
            $table->string('state', 30)->index();
            $table->string('backup_reference')->nullable();
            $table->string('source_schema_version')->nullable();
            $table->string('target_schema_version');
            $table->uuid('operator_uuid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('final_report_json')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_upgrade_steps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->index();
            $table->string('step_code', 150);
            $table->string('state', 30)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('cursor')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_uuid', 'step_code']);
        });

        Schema::create('legacy_record_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 150);
            $table->string('source_key', 255);
            $table->uuid('source_uuid')->nullable();
            $table->string('target_type', 100);
            $table->uuid('target_uuid')->index();
            $table->unsignedInteger('target_version')->nullable();
            $table->timestamps();
            $table->unique(['source_table', 'source_key', 'target_type'], 'legacy_mapping_unique');
        });

        Schema::create('migration_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->index();
            $table->string('step_code', 150);
            $table->string('severity', 20)->index();
            $table->string('error_code', 150);
            $table->string('source_table', 150)->nullable();
            $table->string('source_key', 255)->nullable();
            $table->json('details_json');
            $table->string('resolution_status', 30)->default('open')->index();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        Schema::create('reconciliation_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->index();
            $table->string('check_code', 150);
            $table->string('entity_family', 100);
            $table->unsignedBigInteger('expected_count')->nullable();
            $table->unsignedBigInteger('actual_count')->nullable();
            $table->string('expected_hash')->nullable();
            $table->string('actual_hash')->nullable();
            $table->boolean('passed');
            $table->json('details_json')->nullable();
            $table->timestamps();
            $table->unique(['run_uuid', 'check_code']);
        });

        Schema::create('platform_change_journal', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 100);
            $table->string('legacy_key', 255);
            $table->string('operation', 30);
            $table->json('payload_json');
            $table->timestamp('captured_at');
            $table->timestamp('replayed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'platform_change_journal',
            'reconciliation_results',
            'migration_anomalies',
            'legacy_record_mappings',
            'platform_upgrade_steps',
            'platform_upgrade_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
