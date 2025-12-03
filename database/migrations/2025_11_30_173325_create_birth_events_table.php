<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Create new birth_events table
        Schema::create('birth_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->string('livestockUuid');
            $table->index('livestockUuid');

            // NEW: Enum field to distinguish calving vs farrowing
            $table->enum('eventType', ['calving', 'farrowing'])->default('calving');

            $table->string('startDate');
            $table->string('endDate')->nullable();

            $table->foreignId('calvingTypeId')->constrained('calving_types')->cascadeOnDelete();
            $table->foreignId('calvingProblemsId')->nullable()->constrained('calving_problems')->cascadeOnDelete();
            $table->foreignId('reproductiveProblemId')->nullable()->constrained('reproductive_problems')->cascadeOnDelete();

            $table->string('remarks')->nullable();
            $table->enum('status', ['pending', 'not_active', 'active'])->default('active');

            $table->timestamps();
        });

        // Step 2: Migrate existing data from calvings to birth_events
        // Determine eventType based on livestock species
        if (Schema::hasTable('calvings')) {
            DB::statement("
                INSERT INTO birth_events (
                    id, uuid, farmUuid, livestockUuid, eventType, startDate, endDate,
                    birthTypeId, birthProblemsId, reproductiveProblemId, remarks, status,
                    created_at, updated_at
                )
                SELECT
                    c.id,
                    c.uuid,
                    c.farmUuid,
                    c.livestockUuid,
                    CASE
                        WHEN LOWER(s.name) = 'pig' THEN 'farrowing'
                        ELSE 'calving'
                    END as eventType,
                    c.startDate,
                    c.endDate,
                    c.calvingTypeId as birthTypeId,
                    c.calvingProblemsId as birthProblemsId,
                    c.reproductiveProblemId,
                    c.remarks,
                    c.status,
                    c.created_at,
                    c.updated_at
                FROM calvings c
                LEFT JOIN livestocks l ON c.livestockUuid = l.uuid
                LEFT JOIN species s ON l.speciesId = s.id
            ");
        }

        // Step 3: Drop old calvings table
        Schema::dropIfExists('calvings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate calvings table (without eventType)
        Schema::create('calvings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('startDate');
            $table->string('endDate')->nullable();
            $table->foreignId('calvingTypeId')->constrained('calving_types')->cascadeOnDelete();
            $table->foreignId('calvingProblemsId')->nullable()->constrained('calving_problems')->cascadeOnDelete();
            $table->foreignId('reproductiveProblemId')->nullable()->constrained('reproductive_problems')->cascadeOnDelete();
            $table->string('remarks')->nullable();
            $table->enum('status', ['pending', 'not_active', 'active'])->default('active');
            $table->timestamps();
        });

        // Migrate data back (only calving events)
        if (Schema::hasTable('birth_events')) {
            DB::statement("
                INSERT INTO calvings (
                    id, uuid, farmUuid, livestockUuid, startDate, endDate,
                    calvingTypeId, calvingProblemsId, reproductiveProblemId, remarks, status,
                    created_at, updated_at
                )
                SELECT
                    id, uuid, farmUuid, livestockUuid, startDate, endDate,
                    birthTypeId as calvingTypeId, birthProblemsId as calvingProblemsId, reproductiveProblemId, remarks, status,
                    created_at, updated_at
                FROM birth_events
                WHERE eventType = 'calving'
            ");
        }

        Schema::dropIfExists('birth_events');
    }
};

